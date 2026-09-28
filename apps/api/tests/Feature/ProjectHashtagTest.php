<?php

namespace Tests\Feature;

use App\Models\KeywordProject;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectHashtagTest extends TestCase
{
    use RefreshDatabase;

    public function test_hashtags_are_normalized_saved_and_reset(): void
    {
        $project = KeywordProject::factory()->create();
        $project->analyses()->create(['analyzer_version' => 'x', 'guide_json' => ['hashtags' => [['tag' => '추천태그', 'source' => 'keyword']]]]);
        $url = "/api/projects/{$project->id}/hashtags";

        $this->actingAs($project->user)->putJson($url, ['hashtags' => ['#인계동 맛집', '인계동맛집', '#123', ' 수원_데이트! ', '']])
            ->assertOk()
            ->assertJsonPath('data.hashtags', ['인계동맛집', '수원_데이트']);
        $this->assertSame(['인계동맛집', '수원_데이트'], $project->fresh()->hashtags());

        $this->actingAs($project->user)->putJson($url, ['hashtags' => null])->assertJsonPath('data.hashtags', null);
        $this->assertSame(['추천태그'], $project->fresh()->hashtags());
    }

    public function test_limits_and_authorization(): void
    {
        $project = KeywordProject::factory()->create();

        $this->actingAs($project->user)->putJson("/api/projects/{$project->id}/hashtags", ['hashtags' => array_fill(0, 31, 'a')])
            ->assertJsonValidationErrors('hashtags');
        $this->actingAs(User::factory()->create())->putJson("/api/projects/{$project->id}/hashtags", ['hashtags' => []])
            ->assertForbidden();
    }

    public function test_post_shows_hashtags_it_will_get(): void
    {
        $post = Post::factory()->create();
        $post->project->forceFill(['hashtags_json' => ['인계동파스타']])->save();

        $this->actingAs($post->user)->getJson("/api/posts/{$post->id}")
            ->assertJsonPath('data.recommended_hashtags', ['인계동파스타']);
        $this->actingAs($post->user)->getJson('/api/posts')
            ->assertJsonMissingPath('data.0.recommended_hashtags');
    }
}
