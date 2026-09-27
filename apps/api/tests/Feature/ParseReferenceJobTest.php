<?php

namespace Tests\Feature;

use App\Enums\ParseStatus;
use App\Jobs\ParseReferenceJob;
use App\Models\KeywordProject;
use App\Models\ReferenceDocument;
use App\Services\AiWorker\AiWorkerClient;
use App\Services\AiWorker\AiWorkerUnavailableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ParseReferenceJobTest extends TestCase
{
    use RefreshDatabase;

    private KeywordProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
        $this->project = KeywordProject::factory()->create();
    }

    private function reference(array $attributes = []): ReferenceDocument
    {
        return $this->project->references()->create($attributes + [
            'source_type' => 'user_url', 'source_url' => 'https://ex.com/p/1', 'usage_permission' => 'user_provided',
            'parse_status' => 'pending',
        ]);
    }

    private function parsed(string $hash = 'abc'): array
    {
        return [
            'final_url' => 'https://ex.com/p/1', 'title' => '수원 파스타 후기', 'author' => '홍길동',
            'published_at' => '2026-09-01', 'content_hash' => $hash, 'extractor' => 'trafilatura',
            'features' => [
                'version' => 'features-1', 'char_count' => 1800, 'paragraph_count' => 7, 'image_count' => 6,
                'heading_count' => 3, 'keyword' => ['full_match_count' => 4], 'layout' => 'IPHPIIP',
            ],
        ];
    }

    private function parse(ReferenceDocument $reference): void
    {
        (new ParseReferenceJob($reference))->handle(app(AiWorkerClient::class));
    }

    public function test_success_stores_metadata_and_basic_features(): void
    {
        Http::fake(['*/references/parse' => Http::response($this->parsed())]);
        $reference = $this->reference();

        $this->parse($reference);

        $reference->refresh();
        $this->assertSame(ParseStatus::Parsed, $reference->parse_status);
        $this->assertSame(['수원 파스타 후기', '홍길동', '2026-09-01', 'abc'], [
            $reference->title, $reference->author, $reference->published_at->toDateString(), $reference->content_hash,
        ]);
        $features = $reference->features;
        $this->assertSame([1800, 7, 6, 4, 'features-1'], [
            $features->char_count, $features->paragraph_count, $features->image_count,
            $features->keyword_frequency, $features->analyzer_version,
        ]);
        $this->assertSame(['IPHPIIP', 3, 'trafilatura'], [
            $features->features_json['layout'], $features->features_json['heading_count'], $features->features_json['extractor'],
        ]);
        $this->assertNull($reference->raw_storage_key);
        Http::assertSent(fn ($r) => $r['source'] === 'url' && $r['url'] === 'https://ex.com/p/1'
            && $r['keyword'] === $this->project->keyword);
    }

    public function test_same_content_in_project_is_marked_duplicate(): void
    {
        $this->reference(['content_hash' => 'same', 'parse_status' => 'parsed', 'source_url' => 'https://ex.com/original']);
        Http::fake(['*/references/parse' => Http::response($this->parsed('same'))]);
        $reference = $this->reference(['source_url' => 'https://mirror.ex.com/copy']);

        $this->parse($reference);

        $this->assertSame(ParseStatus::Duplicate, $reference->fresh()->parse_status);
    }

    public function test_pasted_text_is_sent_and_removed_after_success(): void
    {
        Http::fake(['*/references/parse' => Http::response($this->parsed())]);
        $reference = $this->reference(['source_url' => 'https://blog.naver.com/a/1', 'title' => '내가 붙인 제목']);
        $key = ParseReferenceJob::textKey($reference);
        Storage::disk('uploads')->put($key, '본문');

        $this->parse($reference);

        Http::assertSent(fn ($r) => $r['source'] === 'text' && $r['text_key'] === $key && $r['title'] === '내가 붙인 제목');
        Storage::disk('uploads')->assertMissing($key);
        $this->assertSame('내가 붙인 제목', $reference->fresh()->title);
    }

    public function test_permanent_rejection_marks_failed_without_retry(): void
    {
        Http::fake(['*/references/parse' => Http::response(['detail' => ['code' => 'robots_disallowed', 'message' => 'robots.txt가 허용하지 않습니다.']], 422)]);
        $reference = $this->reference();

        $this->parse($reference);

        $reference->refresh();
        $this->assertSame(ParseStatus::Failed, $reference->parse_status);
        $this->assertSame('robots.txt가 허용하지 않습니다.', $reference->error_message);
    }

    public function test_transient_failure_throws_for_retry_then_marks_failed(): void
    {
        Http::fake(['*/references/parse' => Http::response(['detail' => ['code' => 'timeout']], 502)]);
        $reference = $this->reference();
        $job = new ParseReferenceJob($reference);

        try {
            $job->handle(app(AiWorkerClient::class));
            $this->fail('재시도를 위해 예외가 나야 합니다.');
        } catch (AiWorkerUnavailableException $e) {
            $job->failed($e);
        }

        $this->assertSame(ParseStatus::Failed, $reference->fresh()->parse_status);
        $this->assertStringContainsString('잠시 뒤', $reference->fresh()->error_message);
    }
}
