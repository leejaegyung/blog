<?php

namespace Tests\Feature;

use App\Models\KeywordProject;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_guest_cannot_access_projects(): void
    {
        $this->getJson('/api/projects')->assertUnauthorized();
    }

    public function test_create_project_normalizes_whitespace(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/projects', ['keyword' => '  수원   인계동 파스타 ', 'category' => '맛집'])
            ->assertCreated()
            ->assertJsonPath('data.keyword', '수원 인계동 파스타')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.reference_count', 0);
    }

    public function test_keyword_is_required_and_unique_per_user(): void
    {
        KeywordProject::factory()->for($this->user)->create(['keyword' => '수원 맛집']);

        $this->actingAs($this->user)->postJson('/api/projects', [])
            ->assertJsonValidationErrors('keyword');

        $this->actingAs($this->user)->postJson('/api/projects', ['keyword' => '수원  맛집'])
            ->assertJsonValidationErrors(['keyword' => '이미 같은 키워드의 프로젝트가 있습니다.']);

        // 다른 사용자는 같은 키워드를 쓸 수 있다
        $this->actingAs(User::factory()->create())->postJson('/api/projects', ['keyword' => '수원 맛집'])
            ->assertCreated();
    }

    public function test_index_lists_only_own_projects_with_counts(): void
    {
        $mine = KeywordProject::factory()->for($this->user)->create();
        Post::factory()->for($mine, 'project')->create();
        KeywordProject::factory()->create();

        $this->actingAs($this->user)->getJson('/api/projects')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.post_count', 1);
    }

    public function test_update_project(): void
    {
        $project = KeywordProject::factory()->for($this->user)->create(['keyword' => '수원 맛집']);

        // 자기 자신의 키워드는 unique 검사에서 제외된다
        $this->actingAs($this->user)
            ->patchJson("/api/projects/{$project->id}", ['keyword' => '수원 맛집', 'category' => '카페'])
            ->assertOk()
            ->assertJsonPath('data.category', '카페');
    }

    public function test_delete_project_keeps_posts(): void
    {
        $project = KeywordProject::factory()->for($this->user)->create();
        $post = Post::factory()->for($project, 'project')->create();

        $this->actingAs($this->user)->deleteJson("/api/projects/{$project->id}")->assertNoContent();

        $this->assertModelMissing($project);
        $this->assertNull($post->fresh()->keyword_project_id);
    }

    public function test_cannot_touch_other_users_project(): void
    {
        $other = KeywordProject::factory()->create();

        $this->actingAs($this->user)->getJson("/api/projects/{$other->id}")->assertForbidden();
        $this->actingAs($this->user)->patchJson("/api/projects/{$other->id}", ['category' => 'x'])->assertForbidden();
        $this->actingAs($this->user)->deleteJson("/api/projects/{$other->id}")->assertForbidden();
    }
}
