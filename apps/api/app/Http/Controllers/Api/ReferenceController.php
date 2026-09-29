<?php

namespace App\Http\Controllers\Api;

use App\Enums\ParseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReferencesRequest;
use App\Http\Resources\ReferenceResource;
use App\Jobs\ParseReferenceJob;
use App\Models\KeywordProject;
use App\Models\ReferenceDocument;
use App\Support\ReferenceUrl;
use App\Support\Platform;
use App\Services\KakaoSearch;
use App\Jobs\AnalyzeKeywordJob;
use App\Enums\ProjectStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ReferenceController extends Controller
{
    public function index(KeywordProject $project): AnonymousResourceCollection
    {
        Gate::authorize('view', $project);

        return ReferenceResource::collection($project->references()->with('features')->latest('id')->get());
    }

    public function store(StoreReferencesRequest $request, KeywordProject $project): JsonResponse
    {
        Gate::authorize('update', $project);

        [$created, $skipped] = $request->has('urls')
            ? $this->storeUrls($project, $request->validated('urls'))
            : $this->storeTextFor($project, $request->validated('text'), $request->validated('title'), $request->validated('source_url'));

        return response()->json([
            'data' => ReferenceResource::collection(collect($created)->each->load('features')),
            'skipped' => $skipped,
        ], 201);
    }

    /** 네이버 글처럼 서버가 가져오지 않는 참고자료에 본문을 붙여넣는다. */
    public function text(Request $request, ReferenceDocument $reference): ReferenceResource
    {
        Gate::authorize('update', $reference);

        $data = $request->validate([
            'text' => ['required', 'string', 'min:20', 'max:100000'],
            'title' => ['nullable', 'string', 'max:200'],
        ], ['text.min' => '본문이 너무 짧습니다.']);

        Storage::disk('uploads')->put(ParseReferenceJob::textKey($reference), $data['text']);
        $reference->update([
            'title' => $data['title'] ?? $reference->title,
            'parse_status' => ParseStatus::Pending,
            'error_message' => null,
        ]);
        ParseReferenceJob::dispatch($reference);

        return new ReferenceResource($reference->load('features'));
    }

    public function reparse(ReferenceDocument $reference): ReferenceResource
    {
        Gate::authorize('update', $reference);

        // 본문은 분석 후 지우므로, 서버가 다시 가져올 수 없는 글(붙여넣기·네이버)은 다시 붙여넣어야 한다.
        $canRefetch = $reference->source_url && ! ReferenceUrl::isNaver($reference->source_url);
        if (! $canRefetch && ! Storage::disk('uploads')->exists(ParseReferenceJob::textKey($reference))) {
            throw ValidationException::withMessages(['reference' => '본문을 다시 붙여넣어 주세요.']);
        }

        $reference->update(['parse_status' => ParseStatus::Pending, 'error_message' => null]);
        ParseReferenceJob::dispatch($reference);

        return new ReferenceResource($reference->load('features'));
    }

    /**
     * 티스토리 카테고리: 카카오(다음) 검색 상위 티스토리 글을 찾아 참고 글로 넣고 학습을 예약한다.
     * 글 본문은 워커가 robots.txt를 지키며 한 편씩 읽고 특징만 남긴다(원문은 저장하지 않는다).
     */
    public function discover(Request $request, KeywordProject $project, KakaoSearch $kakao): JsonResponse
    {
        Gate::authorize('update', $project);
        if ($project->platform !== Platform::TISTORY) {
            throw ValidationException::withMessages(['project' => '상위 글 자동 찾기는 티스토리 카테고리에서만 써요(네이버는 서버가 가져오지 않아요).']);
        }
        $data = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:100'],
            'size' => ['sometimes', 'integer', 'min:1', 'max:'.self::DISCOVER_MAX],
            'learn' => ['sometimes', 'boolean'],
        ], ['query.required' => '검색어를 넣어 주세요.']);

        $found = $kakao->tistoryPosts(trim($data['query']), $data['size'] ?? 10);
        if ($found['error'] !== null && $found['posts'] === []) {
            throw ValidationException::withMessages(['query' => $found['error']]);
        }
        if ($found['posts'] === []) {
            throw ValidationException::withMessages(['query' => '이 검색어로 찾은 티스토리 글이 없어요. 검색어를 바꿔 보세요.']);
        }

        [$created, $skipped] = $this->storeUrls($project, array_column($found['posts'], 'url'));
        $titles = array_column($found['posts'], 'title', 'url');
        foreach ($created as $reference) {
            $reference->update(['source_type' => 'kakao_search', 'title' => $reference->title ?: ($titles[$reference->source_url] ?? null)]);
        }

        // 글 읽기 작업이 먼저 돌도록 잠시 뒤에 학습한다(이미 학습 대기 중이면 건너뛴다)
        $learning = $created !== [] && $request->boolean('learn', true) && $project->status !== ProjectStatus::Analyzing;
        if ($learning) {
            $project->markAnalysisQueued();
            AnalyzeKeywordJob::dispatch($project)->delay(now()->addSeconds(self::DISCOVER_LEARN_DELAY));
        }

        return response()->json([
            'data' => ReferenceResource::collection(collect($created)->each->load('features')),
            'skipped' => $skipped,
            'found' => count($found['posts']),
            'learning' => $learning,
        ], 201);
    }

    public const DISCOVER_MAX = 20;

    public const DISCOVER_LEARN_DELAY = 60;

    public function destroy(ReferenceDocument $reference): Response
    {
        Gate::authorize('delete', $reference);

        Storage::disk('uploads')->delete(array_filter([
            $reference->raw_storage_key,
            ParseReferenceJob::textKey($reference),
        ]));
        $reference->delete();

        return response()->noContent();
    }

    /**
     * @param  list<string>  $urls
     * @return array{0: list<ReferenceDocument>, 1: list<array{url: string, reason: string}>}
     */
    private function storeUrls(KeywordProject $project, array $urls): array
    {
        // 예전에 저장된 주소도 같은 방식으로 맞춰 비교한다(네이버 PC·모바일·PostView 주소가 같은 글로 보인다)
        $existing = $project->references()->whereNotNull('source_url')->pluck('source_url')->map(fn ($u) => ReferenceUrl::normalize($u))->all();
        $created = [];
        $skipped = [];

        DB::transaction(function () use ($project, $urls, &$existing, &$created, &$skipped) {
            foreach ($urls as $raw) {
                $url = ReferenceUrl::normalize($raw);
                if (in_array($url, $existing, true)) {
                    $skipped[] = ['url' => $url, 'reason' => '이미 등록된 주소입니다.'];

                    continue;
                }
                $existing[] = $url;

                $created[] = $project->references()->create([
                    'source_type' => 'user_url',
                    'source_url' => $url,
                    'usage_permission' => 'user_provided',
                    'parse_status' => ReferenceUrl::isNaver($url) ? ParseStatus::NeedsText : ParseStatus::Pending,
                ]);
            }
        });

        foreach ($created as $reference) {
            if ($reference->parse_status === ParseStatus::Pending) {
                ParseReferenceJob::dispatch($reference);
            }
        }

        return [$created, $skipped];
    }

    /**
     * 본문 추가. 원래 주소가 오면 같은 주소 항목을 찾아 그 항목에 새 본문을 넣고 다시 읽는다(중복으로 만들지 않는다).
     *
     * @return array{0: list<ReferenceDocument>, 1: list<array{url: string, reason: string}>}
     */
    private function storeTextFor(KeywordProject $project, string $text, ?string $title, ?string $sourceUrl): array
    {
        $url = $sourceUrl ? ReferenceUrl::normalize($sourceUrl) : null;
        $existing = $url
            ? $project->references()->whereNotNull('source_url')->get()->first(fn ($r) => ReferenceUrl::normalize($r->source_url) === $url)
            : null;
        // 같은 글을 다시 보내면 새 본문으로 다시 읽는다(본문 필요·실패는 채우고, 이미 읽은 글은 새 기준으로 갱신)
        if ($existing) {
            Storage::disk('uploads')->put(ParseReferenceJob::textKey($existing), $text);
            $existing->update(['title' => $title ?? $existing->title, 'parse_status' => ParseStatus::Pending, 'error_message' => null]);
            ParseReferenceJob::dispatch($existing);

            return [[$existing], []];
        }

        return [[$this->storeText($project, $text, $title, $url)], []];
    }

    private function storeText(KeywordProject $project, string $text, ?string $title, ?string $url = null): ReferenceDocument
    {
        $reference = $project->references()->create([
            'source_type' => 'user_text',
            'source_url' => $url,
            'title' => $title,
            'usage_permission' => 'user_provided',
            'parse_status' => ParseStatus::Pending,
        ]);
        Storage::disk('uploads')->put(ParseReferenceJob::textKey($reference), $text);
        ParseReferenceJob::dispatch($reference);

        return $reference;
    }
}
