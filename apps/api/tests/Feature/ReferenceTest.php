<?php

namespace Tests\Feature;

use App\Jobs\ParseReferenceJob;
use App\Models\KeywordProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReferenceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private KeywordProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('uploads');
        $this->project = KeywordProject::factory()->create();
        $this->user = $this->project->user;
    }

    private function url(): string
    {
        return "/api/projects/{$this->project->id}/references";
    }

    public function test_urls_are_queued_and_naver_urls_wait_for_text(): void
    {
        $response = $this->actingAs($this->user)->postJson($this->url(), ['urls' => [
            'https://tistory.example.com/post/1#comments',
            'https://blog.naver.com/someone/223000000001',
            'https://naver.me/abc',
        ]])->assertCreated();

        $this->assertSame(
            [['https://tistory.example.com/post/1', 'pending'], ['https://blog.naver.com/someone/223000000001', 'needs_text'], ['https://naver.me/abc', 'needs_text']],
            collect($response->json('data'))->map(fn ($r) => [$r['source_url'], $r['parse_status']])->all(),
        );
        Queue::assertPushed(ParseReferenceJob::class, 1);
    }

    public function test_duplicate_urls_are_skipped(): void
    {
        $this->actingAs($this->user)->postJson($this->url(), ['urls' => ['https://ex.com/a']]);

        $this->actingAs($this->user)->postJson($this->url(), ['urls' => ['https://ex.com/a#top', 'https://ex.com/b', 'https://ex.com/b']])
            ->assertCreated()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('skipped.0.url', 'https://ex.com/a')
            ->assertJsonPath('skipped.1.url', 'https://ex.com/b');
    }

    public function test_rejects_invalid_urls_and_limit(): void
    {
        $this->actingAs($this->user)->postJson($this->url(), ['urls' => ['javascript:alert(1)', 'file:///etc/passwd']])
            ->assertJsonValidationErrors(['urls.0', 'urls.1']);

        $urls = array_map(fn ($i) => "https://ex.com/{$i}", range(1, 49));
        $this->actingAs($this->user)->postJson($this->url(), ['urls' => $urls])->assertCreated();
        $this->actingAs($this->user)->postJson($this->url(), ['urls' => ['https://ex.com/50', 'https://ex.com/51']])
            ->assertJsonValidationErrors('urls');
    }

    public function test_store_pasted_text(): void
    {
        $response = $this->actingAs($this->user)->postJson($this->url(), [
            'text' => '인계동 파스타집 후기입니다. 주차가 가능했어요.',
            'title' => '붙여넣은 글',
        ])->assertCreated()->assertJsonPath('data.0.source_type', 'user_text');

        $id = $response->json('data.0.id');
        Storage::disk('uploads')->assertExists("references/{$this->project->id}/{$id}.txt");
        Queue::assertPushed(ParseReferenceJob::class);
    }

    public function test_paste_text_for_naver_reference(): void
    {
        $this->actingAs($this->user)->postJson($this->url(), ['urls' => ['https://m.blog.naver.com/a/1']]);
        $reference = $this->project->references()->sole();

        $this->actingAs($this->user)->postJson("/api/references/{$reference->id}/parse")
            ->assertJsonValidationErrors(['reference' => '본문을 다시 붙여넣어 주세요.']);

        $this->actingAs($this->user)->postJson("/api/references/{$reference->id}/text", ['text' => '네이버 글 본문을 복사해 붙여넣었습니다. 충분히 깁니다.'])
            ->assertOk()
            ->assertJsonPath('data.parse_status', 'pending');

        Storage::disk('uploads')->assertExists("references/{$this->project->id}/{$reference->id}.txt");
        Queue::assertPushed(ParseReferenceJob::class, fn ($job) => $job->reference->is($reference));
    }

    public function test_reparse_failed_reference(): void
    {
        $reference = $this->project->references()->create([
            'source_type' => 'user_url', 'source_url' => 'https://ex.com/a', 'usage_permission' => 'user_provided',
            'parse_status' => 'failed', 'error_message' => 'timeout',
        ]);

        $this->actingAs($this->user)->postJson("/api/references/{$reference->id}/parse")
            ->assertOk()
            ->assertJsonPath('data.parse_status', 'pending')
            ->assertJsonPath('data.error_message', null);
        Queue::assertPushed(ParseReferenceJob::class);
    }

    public function test_pasted_reference_cannot_be_reparsed_after_text_is_gone(): void
    {
        $reference = $this->project->references()->create([
            'source_type' => 'user_text', 'usage_permission' => 'user_provided', 'parse_status' => 'parsed',
        ]);

        $this->actingAs($this->user)->postJson("/api/references/{$reference->id}/parse")
            ->assertJsonValidationErrors('reference');
        Queue::assertNothingPushed();
    }

    public function test_destroy_removes_files(): void
    {
        $reference = $this->project->references()->create([
            'source_type' => 'user_url', 'source_url' => 'https://ex.com/a', 'usage_permission' => 'user_provided',
            'raw_storage_key' => 'references/x.json',
        ]);
        Storage::disk('uploads')->put('references/x.json', '{}');

        $this->actingAs($this->user)->deleteJson("/api/references/{$reference->id}")->assertNoContent();

        $this->assertModelMissing($reference);
        Storage::disk('uploads')->assertMissing('references/x.json');
    }

    public function test_index_includes_feature_counts(): void
    {
        $reference = $this->project->references()->create([
            'source_type' => 'user_url', 'source_url' => 'https://ex.com/a', 'usage_permission' => 'user_provided', 'parse_status' => 'parsed',
        ]);
        $reference->features()->create(['char_count' => 1200, 'image_count' => 7, 'features_json' => ['heading_count' => 3], 'analyzer_version' => 'features-1']);

        $this->actingAs($this->user)->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.0.char_count', 1200)
            ->assertJsonPath('data.0.image_count', 7)
            ->assertJsonPath('data.0.heading_count', 3);
    }

    public function test_other_users_cannot_touch_references(): void
    {
        $reference = $this->project->references()->create([
            'source_type' => 'user_url', 'source_url' => 'https://ex.com/a', 'usage_permission' => 'user_provided',
        ]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->getJson($this->url())->assertForbidden();
        $this->actingAs($stranger)->postJson($this->url(), ['urls' => ['https://ex.com/b']])->assertForbidden();
        $this->actingAs($stranger)->deleteJson("/api/references/{$reference->id}")->assertForbidden();
        $this->actingAs($stranger)->postJson("/api/references/{$reference->id}/parse")->assertForbidden();
    }
}
