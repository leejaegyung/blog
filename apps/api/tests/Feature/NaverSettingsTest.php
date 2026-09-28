<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NaverSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_blog_id_is_saved_from_id_or_full_address_and_shared_with_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/settings/naver', ['blog_id' => 'https://blog.naver.com/leejk4791?Redirect=Write&'])
            ->assertOk()->assertJsonPath('data.blog_id', 'leejk4791');
        $this->actingAs($user)->getJson('/api/user')->assertJsonPath('meta.naver_blog_id', 'leejk4791');

        $this->actingAs($user)->putJson('/api/settings/naver', ['blog_id' => '잘못된 아이디'])->assertJsonValidationErrors('blog_id');
        $this->actingAs($user)->putJson('/api/settings/naver', ['blog_id' => ''])->assertJsonPath('data.blog_id', null);
    }
}
