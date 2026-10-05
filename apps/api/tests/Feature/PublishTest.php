<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\PostImage;
use App\Publishing\PostExporter;
use App\Services\QualityGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class PublishTest extends TestCase
{
    use RefreshDatabase;

    private Post $post;

    private PostImage $first;

    private PostImage $second;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
        $this->post = Post::factory()->create(['title' => '인계동 파스타 <후기>']);
        // 업로드 순서와 본문 순서가 다르다: 본문에 먼저 나오는 사진이 1번
        $this->second = $this->post->images()->create(['storage_key' => 'p/second.jpg', 'sort_order' => 0]);
        $this->first = $this->post->images()->create(['storage_key' => 'p/first.jpg', 'sort_order' => 1]);
        Storage::disk('uploads')->put('p/first.jpg', 'FIRST');
        Storage::disk('uploads')->put('p/second.jpg', 'SECOND');
        $this->post->forceFill(['content_json' => ['blocks' => [
            ['type' => 'paragraph', 'text' => "첫 줄\n둘째 줄 & <b>태그</b>"],
            ['type' => 'image', 'image_id' => $this->first->id],
            ['type' => 'heading', 'text' => '메뉴'],
            ['type' => 'list', 'items' => ['봉골레', '크림']],
            ['type' => 'image', 'image_id' => $this->second->id],
            ['type' => 'quote', 'text' => '또 갈래요'],
        ], 'tags' => ['인계동파스타', '수원맛집']]])->save();
    }

    public function test_export_renders_escaped_html_text_and_numbered_photos(): void
    {
        $response = $this->actingAs($this->post->user)->postJson("/api/posts/{$this->post->id}/export")->assertOk();

        $this->assertSame(implode("\n", [
            '<p>첫 줄<br>둘째 줄 &amp; &lt;b&gt;태그&lt;/b&gt;</p>',
            '<p data-photo="1"><strong>[사진 1]</strong></p>',
            '<h2>메뉴</h2>',
            '<ul><li>봉골레</li><li>크림</li></ul>',
            '<p data-photo="2"><strong>[사진 2]</strong></p>',
            '<blockquote><p>또 갈래요</p></blockquote>',
            '<p>#인계동파스타 #수원맛집</p>',
        ]), $response->json('data.html'));
        $this->assertSame("인계동 파스타 <후기>\n\n첫 줄\n둘째 줄 & <b>태그</b>\n\n[사진 1]\n\n메뉴\n\n- 봉골레\n- 크림\n\n[사진 2]\n\n또 갈래요\n\n#인계동파스타 #수원맛집", $response->json('data.text'));
        $this->assertSame([[1, $this->first->id, '01.jpg'], [2, $this->second->id, '02.jpg']],
            collect($response->json('data.photos'))->map(fn ($p) => [$p['number'], $p['image_id'], $p['filename']])->all());
        $this->assertContains('게시 전 검사를 하지 않았습니다.', $response->json('data.warnings'));
        $this->assertSame(['manual_export', 'exported', 1], [
            $this->post->publishJobs()->sole()->publisher, $this->post->publishJobs()->sole()->status, $this->post->publishJobs()->sole()->attempt,
        ]);
    }

    public function test_connected_place_is_added_before_the_hashtags(): void
    {
        $this->post->forceFill(['place_json' => [
            'name' => '파스타 <인계>', 'road_address' => '경기 수원시 팔달구 인계로 1', 'phone' => '031-000-0000',
            'map_url' => 'https://naver.me/abc?x=1&y=2', 'kakao_url' => 'http://place.map.kakao.com/1',
        ]])->save();

        $data = $this->actingAs($this->post->user)->postJson("/api/posts/{$this->post->id}/export", ['record' => false])->json('data');

        $this->assertStringEndsWith(implode("\n", [
            '<blockquote><p>또 갈래요</p></blockquote>',
            '<p><strong>📍 위치</strong><br>파스타 &lt;인계&gt;<br>경기 수원시 팔달구 인계로 1<br>전화 031-000-0000<br>지도: <a href="https://naver.me/abc?x=1&amp;y=2">https://naver.me/abc?x=1&amp;y=2</a></p>',
            '<p>#인계동파스타 #수원맛집</p>',
        ]), $data['html']);
        $this->assertStringEndsWith("또 갈래요\n\n📍 위치\n파스타 <인계>\n경기 수원시 팔달구 인계로 1\n전화 031-000-0000\n지도: https://naver.me/abc?x=1&y=2\n\n#인계동파스타 #수원맛집", $data['text']);

        // 붙여넣은 링크가 없으면 카카오맵 링크
        $this->post->forceFill(['place_json' => ['name' => '파스타', 'kakao_url' => 'http://place.map.kakao.com/1']])->save();
        $this->assertStringContainsString('지도: http://place.map.kakao.com/1', $this->actingAs($this->post->user)->postJson("/api/posts/{$this->post->id}/export", ['record' => false])->json('data.text'));

        // 여러 곳이면 위치 1, 위치 2
        $this->post->forceFill(['place_json' => [['name' => '카시오 스토어 도산'], ['name' => '서울숲', 'kakao_url' => 'http://place.map.kakao.com/2']]])->save();
        $text = $this->actingAs($this->post->user)->postJson("/api/posts/{$this->post->id}/export", ['record' => false])->json('data.text');
        $this->assertStringContainsString("📍 위치 1\n카시오 스토어 도산\n\n📍 위치 2\n서울숲\n지도: http://place.map.kakao.com/2", $text);
    }

    public function test_background_preparation_does_not_record_an_upload(): void
    {
        $this->actingAs($this->post->user)->postJson("/api/posts/{$this->post->id}/export", ['record' => false])->assertOk();

        $this->assertSame(0, $this->post->publishJobs()->count());
    }

    public function test_export_warns_about_unresolved_quality_errors(): void
    {
        $this->post->load(['facts', 'images']);
        $this->post->forceFill(['quality_json' => [
            'issues' => [['severity' => 'error'], ['severity' => 'error'], ['severity' => 'warning']],
            'source_hash' => QualityGate::sourceHash($this->post),
        ]])->save();

        $warnings = $this->actingAs($this->post->user)->postJson("/api/posts/{$this->post->id}/export")->json('data.warnings');

        $this->assertSame(["게시 전 검사에서 '꼭 고치기' 2개가 남아 있습니다."], $warnings);
    }

    public function test_export_requires_content(): void
    {
        $empty = Post::factory()->create();

        $this->actingAs($empty->user)->postJson("/api/posts/{$empty->id}/export")->assertJsonValidationErrors('post');
    }

    public function test_photo_zip_uses_body_order_filenames(): void
    {
        $response = $this->actingAs($this->post->user)->get("/api/posts/{$this->post->id}/export/photos.zip")->assertOk();

        $zip = new ZipArchive;
        $zip->open($response->getFile()->getPathname());
        $this->assertSame(['01.jpg' => 'FIRST', '02.jpg' => 'SECOND'], [
            $zip->getNameIndex(0) => $zip->getFromIndex(0),
            $zip->getNameIndex(1) => $zip->getFromIndex(1),
        ]);
        $zip->close();
    }

    public function test_record_published_url(): void
    {
        $url = "/api/posts/{$this->post->id}/publish";

        $this->actingAs($this->post->user)->postJson($url, ['published_url' => 'javascript:alert(1)'])
            ->assertJsonValidationErrors(['published_url' => '게시한 글의 주소(https://…)를 넣어 주세요.']);

        $this->actingAs($this->post->user)->postJson($url, ['published_url' => 'https://blog.naver.com/me/223000000000'])
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.published_url', 'https://blog.naver.com/me/223000000000');

        $this->assertSame(PostStatus::Published, $this->post->fresh()->status);
        $this->actingAs($this->post->user)->getJson("/api/posts/{$this->post->id}/publish-status")
            ->assertJsonPath('data.last_job.status', 'published')
            ->assertJsonPath('data.export_count', 0);
    }

    public function test_exporter_filename(): void
    {
        $this->assertSame('09.jpg', PostExporter::filename(9));
        $this->assertSame('12.jpg', PostExporter::filename(12));
    }

    public function test_other_users_are_forbidden(): void
    {
        $stranger = Post::factory()->create()->user;

        $this->actingAs($stranger)->postJson("/api/posts/{$this->post->id}/export")->assertForbidden();
        $this->actingAs($stranger)->get("/api/posts/{$this->post->id}/export/photos.zip")->assertForbidden();
        $this->actingAs($stranger)->postJson("/api/posts/{$this->post->id}/publish", ['published_url' => 'https://x.com'])->assertForbidden();
    }
}
