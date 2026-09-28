<?php

namespace Tests\Feature;

use App\Models\KeywordProject;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private KeywordProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->project = KeywordProject::factory()->for($this->user)->create();
    }

    public function test_create_post_with_facts(): void
    {
        $this->actingAs($this->user)->postJson('/api/posts', [
            'keyword_project_id' => $this->project->id,
            'tone' => 'natural',
            'target_length' => 2500,
            'facts' => [
                ['fact_key' => '장소명', 'fact_value' => 'OO파스타'],
                ['fact_key' => '가격', 'fact_value' => '런치 세트 19,000원'],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.tone', 'natural')
            ->assertJsonPath('data.facts.1.fact_value', '런치 세트 19,000원')
            ->assertJsonPath('data.images', []);

        $this->assertDatabaseHas('post_facts', ['fact_key' => '가격', 'verified' => true, 'source_type' => 'user']);
    }

    public function test_cannot_create_post_in_other_users_project(): void
    {
        $other = KeywordProject::factory()->create();

        $this->actingAs($this->user)->postJson('/api/posts', ['keyword_project_id' => $other->id])
            ->assertJsonValidationErrors('keyword_project_id');
    }

    public function test_rejects_unknown_tone_and_out_of_range_length(): void
    {
        $this->actingAs($this->user)->postJson('/api/posts', [
            'keyword_project_id' => $this->project->id,
            'tone' => 'angry',
            'target_length' => 50,
        ])->assertJsonValidationErrors(['tone', 'target_length']);
    }

    public function test_update_replaces_facts_in_order(): void
    {
        $post = Post::factory()->for($this->project, 'project')->create();
        $post->facts()->create(['fact_key' => 'old', 'fact_value' => 'x']);

        $this->actingAs($this->user)->patchJson("/api/posts/{$post->id}", [
            'title' => '제목',
            'facts' => [
                ['fact_key' => 'b', 'fact_value' => '2'],
                ['fact_key' => 'a', 'fact_value' => '1'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.title', '제목')
            ->assertJsonCount(2, 'data.facts')
            ->assertJsonPath('data.facts.0.fact_key', 'b');

        $this->assertDatabaseMissing('post_facts', ['fact_key' => 'old']);
    }

    public function test_update_without_facts_keeps_them(): void
    {
        $post = Post::factory()->for($this->project, 'project')->create();
        $post->facts()->create(['fact_key' => 'keep', 'fact_value' => 'x']);

        $this->actingAs($this->user)->patchJson("/api/posts/{$post->id}", ['tone' => 'expert'])
            ->assertOk()
            ->assertJsonPath('data.facts.0.fact_key', 'keep');
    }

    public function test_cannot_move_post_to_another_project(): void
    {
        $post = Post::factory()->for($this->project, 'project')->create();

        $this->actingAs($this->user)->patchJson("/api/posts/{$post->id}", ['keyword_project_id' => 1])
            ->assertJsonValidationErrors('keyword_project_id');
    }

    public function test_index_filters_by_project(): void
    {
        Post::factory()->for($this->project, 'project')->create();
        $otherProject = KeywordProject::factory()->for($this->user)->create();
        Post::factory()->for($otherProject, 'project')->create();
        Post::factory()->create();

        $this->actingAs($this->user)->getJson('/api/posts')->assertJsonCount(2, 'data');
        $this->actingAs($this->user)->getJson("/api/posts?project_id={$this->project->id}")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.image_count', 0);
    }

    public function test_cannot_view_or_update_other_users_post(): void
    {
        $post = Post::factory()->create();

        $this->actingAs($this->user)->getJson("/api/posts/{$post->id}")->assertForbidden();
        $this->actingAs($this->user)->patchJson("/api/posts/{$post->id}", ['title' => 'x'])->assertForbidden();
    }

    public function test_delete_removes_post_photos_and_keeps_usage_records(): void
    {
        \Illuminate\Support\Facades\Storage::fake('uploads');
        $post = Post::factory()->create();
        \Illuminate\Support\Facades\Storage::disk('uploads')->put('users/1/a.jpg', 'x');
        \Illuminate\Support\Facades\Storage::disk('uploads')->put('users/1/a_thumb.jpg', 'x');
        $post->images()->create(['storage_key' => 'users/1/a.jpg', 'thumb_key' => 'users/1/a_thumb.jpg']);
        $post->facts()->create(['fact_key' => '가격', 'fact_value' => '1원']);
        $generation = \App\Models\Generation::create([
            'post_id' => $post->id, 'purpose' => 'draft', 'provider' => 'anthropic', 'model' => 'm', 'status' => 'success',
        ]);

        $this->actingAs(User::factory()->create())->deleteJson("/api/posts/{$post->id}")->assertForbidden();
        $this->actingAs($post->user)->deleteJson("/api/posts/{$post->id}")->assertNoContent();

        $this->assertModelMissing($post);
        $this->assertSame(0, \App\Models\PostImage::count() + \App\Models\PostFact::count());
        \Illuminate\Support\Facades\Storage::disk('uploads')->assertMissing(['users/1/a.jpg', 'users/1/a_thumb.jpg']);
        $this->assertNull($generation->fresh()->post_id);
    }
}
