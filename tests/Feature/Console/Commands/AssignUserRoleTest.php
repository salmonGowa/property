<?php

namespace Tests\Feature\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AssignUserRoleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_assigns_the_role_and_reactivates_the_user(): void
    {
        $user = User::factory()->withRole(null)->inactive()->create(['email' => 'owner@example.co.ke']);

        $this->artisan('user:assign-role', ['email' => 'owner@example.co.ke', 'role' => 'admin'])->assertSuccessful();

        $user->refresh();
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertTrue($user->is_active);
    }

    public function test_rejects_an_unknown_role(): void
    {
        User::factory()->create(['email' => 'owner@example.co.ke']);

        $this->artisan('user:assign-role', ['email' => 'owner@example.co.ke', 'role' => 'superuser'])->assertFailed();
    }

    public function test_rejects_an_unknown_email(): void
    {
        $this->artisan('user:assign-role', ['email' => 'nobody@example.co.ke'])->assertFailed();
    }
}
