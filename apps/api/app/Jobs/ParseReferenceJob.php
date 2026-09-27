<?php

namespace App\Jobs;

use App\Enums\ParseStatus;
use App\Models\ReferenceDocument;
use App\Services\AiWorker\AiWorkerClient;
use App\Services\AiWorker\AiWorkerUnavailableException;
use App\Services\AiWorker\ReferenceRejectedException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ParseReferenceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public ReferenceDocument $reference) {}

    public static function textKey(ReferenceDocument $reference): string
    {
        return "references/{$reference->keyword_project_id}/{$reference->id}.txt";
    }

    public function handle(AiWorkerClient $worker): void
    {
        $reference = $this->reference;
        $textKey = self::textKey($reference);
        $hasText = Storage::disk('uploads')->exists($textKey);
        $keyword = $reference->project->keyword;

        try {
            $result = $worker->parseReference($hasText
                ? ['source' => 'text', 'text_key' => $textKey, 'title' => $reference->title, 'keyword' => $keyword]
                : ['source' => 'url', 'url' => $reference->source_url, 'keyword' => $keyword]);
        } catch (ReferenceRejectedException $e) {
            $reference->update(['parse_status' => ParseStatus::Failed, 'error_message' => $e->getMessage()]);

            return;
        }
        // AiWorkerUnavailableException은 그대로 던져 재시도한다.

        $duplicate = ReferenceDocument::query()
            ->where('keyword_project_id', $reference->keyword_project_id)
            ->whereKeyNot($reference->id)
            ->where('content_hash', $result['content_hash'])
            ->where('parse_status', ParseStatus::Parsed)
            ->exists();

        $reference->update([
            'title' => $reference->title ?: $result['title'],
            'author' => $result['author'],
            'published_at' => $this->date($result['published_at']),
            // 본문은 저장하지 않는다(2026-09-27 결정). 특징만 남긴다.
            'raw_storage_key' => null,
            'content_hash' => $result['content_hash'],
            'parse_status' => $duplicate ? ParseStatus::Duplicate : ParseStatus::Parsed,
            'error_message' => null,
            'collected_at' => now(),
        ]);

        $features = $result['features'];
        $reference->features()->updateOrCreate([], [
            'char_count' => $features['char_count'],
            'paragraph_count' => $features['paragraph_count'],
            'image_count' => $features['image_count'],
            'keyword_frequency' => $features['keyword']['full_match_count'],
            'features_json' => $features + ['extractor' => $result['extractor']],
            'analyzer_version' => $features['version'],
        ]);

        if ($hasText) {
            Storage::disk('uploads')->delete($textKey);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->reference->update([
            'parse_status' => ParseStatus::Failed,
            'error_message' => $exception instanceof AiWorkerUnavailableException
                ? '분석 서비스에 연결하지 못했습니다. 잠시 뒤 다시 시도해 주세요.'
                : '처리 중 오류가 발생했습니다.',
        ]);
    }

    private function date(?string $value): ?CarbonImmutable
    {
        try {
            return $value ? CarbonImmutable::parse($value) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
