<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PostImageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
        $this->post = Post::factory()->create();
        $this->user = $this->post->user;
    }

    private function jpeg(string $name = 'photo.jpg'): UploadedFile
    {
        // JPEG 매직 넘버만 있으면 MIME 검사를 통과한다(실제 처리는 워커 fake).
        return $this->upload($name, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00".str_repeat('x', 64));
    }

    /** fake()는 확장자로 MIME을 정하므로, 내용 기반 판별을 검증하려면 실제 파일로 만든다. */
    private function upload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function fakeWorker(): void
    {
        Http::fake(['*/images/process' => function ($request) {
            $prefix = $request['dest_prefix'];
            Storage::disk('uploads')->put("{$prefix}.jpg", 'main');
            Storage::disk('uploads')->put("{$prefix}_thumb.jpg", 'thumb');

            return Http::response([
                'storage_key' => "{$prefix}.jpg",
                'thumb_key' => "{$prefix}_thumb.jpg",
                'mime_type' => 'image/jpeg',
                'width' => 2048,
                'height' => 1536,
                'size_bytes' => 1234,
                'taken_at' => '2026-09-20T13:05:00',
            ]);
        }]);
    }

    public function test_upload_processes_image_via_worker(): void
    {
        $this->fakeWorker();

        $response = $this->actingAs($this->user)
            ->post("/api/posts/{$this->post->id}/images", ['image' => $this->jpeg(), 'strip_exif' => '0'], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.width', 2048)
            ->assertJsonPath('data.sort_order', 0)
            ->assertJsonPath('data.original_name', 'photo.jpg');

        $image = PostImage::sole();
        $this->assertStringStartsWith("users/{$this->user->id}/posts/{$this->post->id}/", $image->storage_key);
        $this->assertSame('2026-09-20 13:05:00', $image->taken_at->format('Y-m-d H:i:s'));
        $this->assertStringContainsString("/images/{$image->id}/file?variant=thumb", $response->json('data.thumb_url'));

        Http::assertSent(fn ($request) => $request['strip_exif'] === false && str_starts_with($request['source'], 'tmp/'));
        // 원본 임시 파일은 처리 후 지운다
        $this->assertSame([], Storage::disk('uploads')->files('tmp'));
    }

    public function test_second_upload_goes_to_the_end(): void
    {
        $this->fakeWorker();

        foreach (['a.jpg', 'b.jpg'] as $name) {
            $this->actingAs($this->user)->post("/api/posts/{$this->post->id}/images", ['image' => $this->jpeg($name)], ['Accept' => 'application/json']);
        }

        $this->assertSame([0, 1], $this->post->images()->pluck('sort_order')->all());
        Http::assertSent(fn ($request) => $request['strip_exif'] === true);
    }

    public function test_rejects_non_image_by_content(): void
    {
        $this->fakeWorker();
        $file = $this->upload('evil.jpg', '<?php echo 1;');

        $this->actingAs($this->user)
            ->post("/api/posts/{$this->post->id}/images", ['image' => $file], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors(['image' => 'JPG, PNG, WEBP, HEIC 사진만 올릴 수 있습니다.']);

        Http::assertNothingSent();
    }

    public function test_worker_rejection_becomes_validation_error(): void
    {
        Http::fake(['*/images/process' => Http::response(['detail' => 'bad'], 422)]);

        $this->actingAs($this->user)
            ->post("/api/posts/{$this->post->id}/images", ['image' => $this->jpeg()], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('image');

        $this->assertDatabaseEmpty('post_images');
        $this->assertSame([], Storage::disk('uploads')->files('tmp'));
    }

    public function test_worker_down_returns_503(): void
    {
        Http::fake(['*/images/process' => Http::response(status: 500)]);

        $this->actingAs($this->user)
            ->post("/api/posts/{$this->post->id}/images", ['image' => $this->jpeg()], ['Accept' => 'application/json'])
            ->assertStatus(503);
    }

    public function test_limits_images_per_post(): void
    {
        $this->fakeWorker();
        for ($i = 0; $i < 30; $i++) {
            $this->post->images()->create(['storage_key' => "k{$i}.jpg", 'sort_order' => $i]);
        }

        $this->actingAs($this->user)
            ->post("/api/posts/{$this->post->id}/images", ['image' => $this->jpeg()], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('image');
    }

    public function test_cannot_upload_to_other_users_post(): void
    {
        $this->fakeWorker();

        $this->actingAs(User::factory()->create())
            ->post("/api/posts/{$this->post->id}/images", ['image' => $this->jpeg()], ['Accept' => 'application/json'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_delete_removes_files(): void
    {
        Storage::disk('uploads')->put('p/a.jpg', 'x');
        Storage::disk('uploads')->put('p/a_thumb.jpg', 'x');
        $image = $this->post->images()->create(['storage_key' => 'p/a.jpg', 'thumb_key' => 'p/a_thumb.jpg']);

        $this->actingAs($this->user)->deleteJson("/api/posts/{$this->post->id}/images/{$image->id}")->assertNoContent();

        $this->assertModelMissing($image);
        Storage::disk('uploads')->assertMissing(['p/a.jpg', 'p/a_thumb.jpg']);
    }

    public function test_image_must_belong_to_post(): void
    {
        $otherPost = Post::factory()->for($this->post->project, 'project')->create();
        $image = $otherPost->images()->create(['storage_key' => 'x.jpg']);

        $this->actingAs($this->user)->deleteJson("/api/posts/{$this->post->id}/images/{$image->id}")->assertNotFound();
    }

    public function test_reorder(): void
    {
        $a = $this->post->images()->create(['storage_key' => 'a', 'sort_order' => 0]);
        $b = $this->post->images()->create(['storage_key' => 'b', 'sort_order' => 1]);
        $c = $this->post->images()->create(['storage_key' => 'c', 'sort_order' => 2]);

        $this->actingAs($this->user)->patchJson("/api/posts/{$this->post->id}/images/order", ['ids' => [$c->id, $a->id, $b->id]])
            ->assertOk()
            ->assertJsonPath('data.0.id', $c->id)
            ->assertJsonPath('data.2.id', $b->id);
    }

    public function test_reorder_requires_exact_image_set(): void
    {
        $a = $this->post->images()->create(['storage_key' => 'a']);
        $this->post->images()->create(['storage_key' => 'b']);
        $foreign = Post::factory()->create()->images()->create(['storage_key' => 'z']);

        $this->actingAs($this->user)->patchJson("/api/posts/{$this->post->id}/images/order", ['ids' => [$a->id]])
            ->assertJsonValidationErrors('ids');
        $this->actingAs($this->user)->patchJson("/api/posts/{$this->post->id}/images/order", ['ids' => [$a->id, $foreign->id]])
            ->assertJsonValidationErrors('ids');
    }

    public function test_serves_file_and_thumbnail_to_owner_only(): void
    {
        Storage::disk('uploads')->put('p/a.jpg', 'MAIN');
        Storage::disk('uploads')->put('p/a_thumb.jpg', 'THUMB');
        $image = $this->post->images()->create(['storage_key' => 'p/a.jpg', 'thumb_key' => 'p/a_thumb.jpg', 'mime_type' => 'image/jpeg']);
        $url = "/api/posts/{$this->post->id}/images/{$image->id}/file";

        $this->actingAs($this->user)->get($url)->assertOk()->assertStreamedContent('MAIN');
        $this->actingAs($this->user)->get("{$url}?variant=thumb")->assertOk()->assertStreamedContent('THUMB');

        $this->actingAs(User::factory()->create())->getJson($url)->assertForbidden();
    }
}
