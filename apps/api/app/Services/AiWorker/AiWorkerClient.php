<?php

namespace App\Services\AiWorker;

use App\Services\LlmSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;

class AiWorkerClient
{
    public function healthy(): bool
    {
        try {
            return $this->http(3)->get('/health')->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    /**
     * 업로드 볼륨 안의 원본을 정규화한다(방향 보정, 리사이즈, JPEG 재인코딩, 썸네일).
     *
     * @return array{storage_key: string, thumb_key: string, mime_type: string, width: int, height: int, size_bytes: int, taken_at: ?string}
     *
     * @throws InvalidImageException 이미지로 읽을 수 없는 파일
     * @throws AiWorkerUnavailableException 워커 연결 실패 또는 서버 오류
     */
    public function processImage(string $source, string $destPrefix, bool $stripExif): array
    {
        try {
            $response = $this->http(60)->post('/images/process', [
                'source' => $source,
                'dest_prefix' => $destPrefix,
                'strip_exif' => $stripExif,
            ]);
        } catch (ConnectionException $e) {
            throw new AiWorkerUnavailableException($e->getMessage(), previous: $e);
        }

        if ($response->status() === 422) {
            throw new InvalidImageException((string) $response->json('detail'));
        }

        if ($response->failed()) {
            throw new AiWorkerUnavailableException("AI Worker 오류 ({$response->status()})");
        }

        return $response->json();
    }

    /**
     * LLM 연결 확인. 모든 대상이 실패해도(503) 시도 기록은 돌려준다.
     *
     * @param  list<string>  $targets
     * @return array{text: ?string, generations: list<array<string, mixed>>}
     *
     * @throws AiWorkerUnavailableException
     */
    public function pingLlm(array $targets = []): array
    {
        try {
            $response = $this->llm(120)->post('/llm/ping', ['targets' => $targets ?: null]);
        } catch (ConnectionException $e) {
            throw new AiWorkerUnavailableException($e->getMessage(), previous: $e);
        }

        if ($response->successful()) {
            return ['text' => $response->json('text'), 'generations' => $response->json('generations')];
        }

        if ($response->status() === 503 && is_array($response->json('detail.generations'))) {
            return ['text' => null, 'generations' => $response->json('detail.generations')];
        }

        throw new AiWorkerUnavailableException("AI Worker 오류 ({$response->status()}): ".$response->body());
    }

    /**
     * 참고자료(URL 또는 붙여넣은 본문)를 읽어 특징만 돌려준다. 본문은 워커도 저장하지 않는다.
     *
     * @param  array<string, ?string>  $payload
     * @return array{final_url: ?string, title: ?string, author: ?string, published_at: ?string, content_hash: string, extractor: string, features: array<string, mixed>}
     *
     * @throws ReferenceRejectedException 다시 시도해도 같은 실패
     * @throws AiWorkerUnavailableException 일시적 실패(워커 장애, 원격 사이트 오류·시간 초과)
     */
    public function parseReference(array $payload): array
    {
        try {
            $response = $this->http(60)->post('/references/parse', $payload);
        } catch (ConnectionException $e) {
            throw new AiWorkerUnavailableException($e->getMessage(), previous: $e);
        }

        if ($response->status() === 422) {
            throw new ReferenceRejectedException(
                (string) $response->json('detail.code', 'rejected'),
                (string) $response->json('detail.message', '가져올 수 없는 참고자료입니다.'),
            );
        }

        if ($response->failed()) {
            throw new AiWorkerUnavailableException('참고자료 파싱 실패 ('.$response->status().'): '.$response->json('detail.code', ''));
        }

        return $response->json();
    }

    /**
     * 참고자료 특징 → 키워드 통계(코드) + AI 해석(LLM). LLM이 모두 실패해도 통계와 시도 기록은 돌려준다.
     *
     * @param  array{keyword: string, category: ?string, features: list<array<string, mixed>>}  $payload
     * @return array{stats: ?array<string, mixed>, insight: ?array<string, mixed>, insight_error: ?string, prompt_version: string, generations: list<array<string, mixed>>}
     *
     * @throws AiWorkerUnavailableException
     */
    public function analyzeKeyword(array $payload): array
    {
        try {
            $response = $this->llm(240)->post('/keywords/analyze', $payload);
        } catch (ConnectionException $e) {
            throw new AiWorkerUnavailableException($e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new AiWorkerUnavailableException("키워드 분석 실패 ({$response->status()})");
        }

        return $response->json();
    }

    /**
     * Writing Plan. 모든 LLM 대상이 실패하면 plan=null과 시도 기록을 돌려준다.
     *
     * @param  array<string, mixed>  $payload
     * @return array{plan: ?array<string, mixed>, plan_error: ?string, prompt_version: string, generations: list<array<string, mixed>>}
     *
     * @throws AiWorkerUnavailableException
     */
    public function planPost(array $payload): array
    {
        try {
            $response = $this->llm(240)->post('/posts/plan', $payload);
        } catch (ConnectionException $e) {
            throw new AiWorkerUnavailableException($e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new AiWorkerUnavailableException("글 계획 실패 ({$response->status()})");
        }

        return $response->json();
    }

    /**
     * 확정된 계획으로 초안을 쓰고 코드 검증 결과를 돌려준다. 모든 LLM 대상이 실패하면 draft=null.
     *
     * @param  array<string, mixed>  $payload
     * @return array{draft: ?array<string, mixed>, draft_error: ?string, prompt_version: string, generations: list<array<string, mixed>>}
     *
     * @throws AiWorkerUnavailableException
     */
    public function draftPost(array $payload): array
    {
        try {
            $response = $this->llm(270)->post('/posts/draft', $payload);
        } catch (ConnectionException $e) {
            throw new AiWorkerUnavailableException($e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new AiWorkerUnavailableException("초안 생성 실패 ({$response->status()})");
        }

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{text: ?string, warnings: list<array{code: string, message: string}>, error: ?string, prompt_version: string, generations: list<array<string, mixed>>}
     *
     * @throws AiWorkerUnavailableException
     */
    public function rewriteParagraph(array $payload): array
    {
        try {
            $response = $this->llm(120)->post('/posts/rewrite', $payload);
        } catch (ConnectionException $e) {
            throw new AiWorkerUnavailableException($e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new AiWorkerUnavailableException("문단 다시 쓰기 실패 ({$response->status()})");
        }

        return $response->json();
    }

    /**
     * 사진 분석. 배치 일부가 실패해도 성공한 결과와 실패한 사진 id를 함께 돌려준다.
     *
     * @param  array<string, mixed>  $payload
     * @return array{results: list<array<string, mixed>>, failed_ids: list<int>, error: ?string, prompt_version: string, generations: list<array<string, mixed>>}
     *
     * @throws AiWorkerUnavailableException
     */
    public function analyzeImages(array $payload): array
    {
        try {
            $response = $this->llm(280)->post('/images/analyze', $payload);
        } catch (ConnectionException $e) {
            throw new AiWorkerUnavailableException($e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new AiWorkerUnavailableException("사진 분석 실패 ({$response->status()})");
        }

        return $response->json();
    }

    /**
     * 코드 규칙 기반 품질 검사(LLM 호출 없음).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws AiWorkerUnavailableException
     */
    public function qualityCheck(array $payload): array
    {
        try {
            $response = $this->http(30)->post('/posts/quality-check', $payload);
        } catch (ConnectionException $e) {
            throw new AiWorkerUnavailableException($e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new AiWorkerUnavailableException("품질 검사 실패 ({$response->status()})");
        }

        return $response->json();
    }

    /** LLM을 부르지 않는 호출(헬스·사진 처리·파싱·품질 검사)에는 API 키를 보내지 않는다 */
    private function http(int $timeout): PendingRequest
    {
        return Http::baseUrl(config('services.ai_worker.url'))
            ->acceptJson()
            ->timeout($timeout)
            ->withHeaders(array_filter(['X-Request-Id' => Context::get('trace_id')]));
    }

    /** 관리 화면에서 바꾼 API 키·시도 순서를 헤더로 넘긴다(워커 재시작 불필요). 로그에 남기지 않는다 */
    private function llm(int $timeout): PendingRequest
    {
        return $this->http($timeout)->withHeaders(['X-LLM-Config' => app(LlmSettings::class)->workerHeader()]);
    }
}
