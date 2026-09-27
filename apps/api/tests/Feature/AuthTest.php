<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum은 stateful 도메인에서 온 요청에만 세션을 붙인다.
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_login_with_valid_credentials(): void
    {
        $user = User::factory()->create(['email' => 'me@example.com']);

        $this->postJson('/api/login', ['login' => 'me@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.email', 'me@example.com');

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_login_rejects_wrong_password(): void
    {
        User::factory()->create(['email' => 'me@example.com']);

        $this->postJson('/api/login', ['login' => 'me@example.com', 'password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('login');

        $this->assertGuest('web');
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'me@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['login' => 'me@example.com', 'password' => 'wrong']);
        }

        $this->postJson('/api/login', ['login' => 'me@example.com', 'password' => 'password'])
            ->assertTooManyRequests();
    }

    public function test_current_user_requires_authentication(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_current_user_and_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/user')->assertOk()->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('meta.auto_login', false);

        $this->postJson('/api/logout')->assertNoContent();
        $this->assertGuest('web');
    }

    public function test_create_user_command(): void
    {
        $this->artisan('app:create-user', ['login' => 'owner@example.com', '--password' => 'secret-pass'])
            ->assertSuccessful();

        $this->postJson('/api/login', ['login' => 'owner@example.com', 'password' => 'secret-pass'])->assertOk();
    }

    public function test_username_account_logs_in_by_username(): void
    {
        $this->artisan('app:create-user', ['login' => 'admin', '--password' => 'test-pass-9876'])->assertSuccessful();

        $this->postJson('/api/login', ['login' => 'admin', 'password' => 'test-pass-9876'])
            ->assertOk()
            ->assertJsonPath('data.username', 'admin');

        $this->artisan('app:create-user', ['login' => 'bad name!', '--password' => 'test-pass-9876'])->assertFailed();
    }

    public function test_auto_login_signs_in_configured_user_for_browser_requests(): void
    {
        config(['auth.auto_login.enabled' => true, 'auth.auto_login.username' => 'admin']);
        $admin = User::factory()->create(['username' => 'admin']);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.id', $admin->id)
            ->assertJsonPath('meta.auto_login', true);
        $this->getJson('/api/projects')->assertOk();
    }

    public function test_auto_login_does_nothing_when_disabled_or_without_session(): void
    {
        User::factory()->create(['username' => 'admin']);

        config(['auth.auto_login.enabled' => false]);
        $this->getJson('/api/user')->assertUnauthorized();

        // 세션이 없는 요청(브라우저가 아닌 호출)은 자동 로그인하지 않는다
        config(['auth.auto_login.enabled' => true]);
        $this->withHeader('Referer', 'http://not-stateful.example')->getJson('/api/user')->assertUnauthorized();
    }

    public function test_create_user_command_rejects_short_password(): void
    {
        $this->artisan('app:create-user', ['login' => 'owner@example.com', '--password' => 'short'])
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'owner@example.com']);
    }
}
