<?php

namespace Tests\Feature;

use App\Jobs\ParseReferenceJob;
use App\Models\KeywordProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 북마크 버튼 "Blog AI로 보내기": 브라우저가 읽은 본문을 원래 주소와 함께 받는다(서버는 그 주소에 접속하지 않는다) */
class ReferenceSendTest extends TestCase
{
    use RefreshDatabase;

    public function test_text_with_source_url_is_stored_parsed_from_text_and_deduped(): void
    {
        Queue::fake();
        Storage::fake('uploads');
        $user = User::factory()->create();
        $category = $user->keywordProjects()->create(['keyword' => '맛집', 'kind' => KeywordProject::KIND_CATEGORY]);
        $url = "/api/projects/{$category->id}/references";
        $body = ['title' => '인계동 파스타 후기', 'text' => "[사진]\n인계동 파스타 다녀왔어요. 맛있었어요.\n\n# 메뉴\n봉골레 18,000원", 'source_url' => 'https://blog.naver.com/me/123'];

        $id = $this->actingAs($user)->postJson($url, $body)->assertCreated()
            ->assertJsonPath('data.0.source_url', 'https://blog.naver.com/me/123')
            ->assertJsonPath('data.0.parse_status', 'pending')
            ->json('data.0.id');
        Storage::disk('uploads')->assertExists(ParseReferenceJob::textKey($category->references()->find($id)));
        Queue::assertPushed(ParseReferenceJob::class, 1);

        // 같은 글을 또 보내면 건너뛴다(이미 읽는 중·읽음)
        $this->actingAs($user)->postJson($url, $body)->assertCreated()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('skipped.0.reason', '이미 등록된 글입니다.');
    }

    public function test_sending_fills_a_naver_url_that_needed_text(): void
    {
        Queue::fake();
        Storage::fake('uploads');
        $user = User::factory()->create();
        $category = $user->keywordProjects()->create(['keyword' => '맛집', 'kind' => KeywordProject::KIND_CATEGORY]);
        $url = "/api/projects/{$category->id}/references";
        $waiting = $this->actingAs($user)->postJson($url, ['urls' => ['https://blog.naver.com/me/9']])
            ->assertJsonPath('data.0.parse_status', 'needs_text')->json('data.0.id');

        $this->actingAs($user)->postJson($url, ['text' => str_repeat('본문 내용입니다. ', 5), 'source_url' => 'https://blog.naver.com/me/9'])
            ->assertCreated()
            ->assertJsonPath('data.0.id', $waiting)
            ->assertJsonPath('data.0.parse_status', 'pending');
        $this->assertSame(1, $category->references()->count());
    }
}
