<?php

namespace Tests\Feature\Filament;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_active_user_with_a_role_can_open_the_dashboard(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::GateOfficer)->create())
            ->get('/admin')
            ->assertOk();
    }

    public function test_deactivated_user_is_forbidden(): void
    {
        $this->actingAs(User::factory()->inactive()->create())
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_user_without_a_role_is_forbidden(): void
    {
        $this->actingAs(User::factory()->withRole(null)->create())
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_sign_in_and_failed_sign_in_are_audited(): void
    {
        $user = User::factory()->create(['email' => 'finance@example.com']);

        Auth::attempt(['email' => 'finance@example.com', 'password' => 'wrong-password']);
        Auth::attempt(['email' => 'finance@example.com', 'password' => 'password']);

        $this->assertSame(['email' => 'finance@example.com'], AuditLog::query()->where('event', 'auth.failed')->sole()->newValues());
        $this->assertSame($user->id, (int) AuditLog::query()->where('event', 'auth.login')->sole()->auditable_id);
    }
}
