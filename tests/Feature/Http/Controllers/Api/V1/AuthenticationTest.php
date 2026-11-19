<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum only treats requests from first-party origins as stateful (cookie) requests.
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_signs_in_with_valid_credentials_and_returns_the_user_with_role_permissions(): void
    {
        User::factory()->withRole(UserRole::Accountant)->create(['email' => 'wanjiku@example.com', 'name' => 'Wanjiku']);

        $this->postJson('/api/v1/auth/login', ['email' => 'wanjiku@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Wanjiku')
            ->assertJsonPath('data.role', ['value' => 'accountant', 'label' => 'Accountant'])
            ->assertJsonPath('data.permissions.properties', ['viewAny' => true, 'create' => false])
            ->assertJsonPath('data.permissions.users', ['viewAny' => false, 'create' => false]);

        $this->assertAuthenticated('web');
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', 'wanjiku@example.com');
    }

    public function test_rejects_a_wrong_password(): void
    {
        User::factory()->create(['email' => 'wanjiku@example.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'wanjiku@example.com', 'password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'These credentials do not match our records.']);

        $this->assertGuest('web');
    }

    public function test_deactivated_users_cannot_sign_in(): void
    {
        User::factory()->inactive()->create(['email' => 'wanjiku@example.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'wanjiku@example.com', 'password' => 'password'])
            ->assertJsonValidationErrors(['email' => 'These credentials do not match our records.']);

        $this->assertGuest('web');
    }

    public function test_users_without_a_role_cannot_sign_in(): void
    {
        User::factory()->withRole(null)->create(['email' => 'wanjiku@example.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'wanjiku@example.com', 'password' => 'password'])
            ->assertUnprocessable();

        $this->assertGuest('web');
    }

    public function test_login_is_rate_limited_after_five_attempts(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/login', ['email' => 'wanjiku@example.com', 'password' => 'wrong'])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'wanjiku@example.com', 'password' => 'wrong'])->assertTooManyRequests();
    }

    public function test_guests_receive_401(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_a_user_deactivated_mid_session_is_locked_out(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $user->update(['is_active' => false]);

        $this->getJson('/api/v1/me')
            ->assertForbidden()
            ->assertJsonPath('message', 'Your account has been deactivated.');
    }

    public function test_logout_ends_the_session(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertGuest('web');
    }
}
