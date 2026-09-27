<?php

namespace Tests\Feature;

use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_tables_exist(): void
    {
        foreach ([
            'blogs', 'keyword_projects', 'reference_documents', 'document_features',
            'keyword_analyses', 'posts', 'post_facts', 'post_images', 'generations',
            'publish_jobs', 'prompt_templates', 'jobs', 'job_batches', 'failed_jobs',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} 테이블이 없습니다.");
        }
    }

    public function test_deleting_post_cascades_to_children(): void
    {
        $post = Post::factory()->create();
        $post->facts()->create(['fact_key' => 'price', 'fact_value' => '19,000원']);
        $post->images()->create(['storage_key' => 'a.jpg']);
        $post->generations()->create(['purpose' => 'draft', 'provider' => 'anthropic', 'model' => 'm']);

        $post->delete();

        $this->assertDatabaseEmpty('post_facts');
        $this->assertDatabaseEmpty('post_images');
        $this->assertDatabaseEmpty('generations');
    }
}
