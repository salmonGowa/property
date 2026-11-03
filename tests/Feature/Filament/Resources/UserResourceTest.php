<?php

namespace Tests\Feature\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UserResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_creates_a_user_with_a_role(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::Admin)->create());

        Livewire::test(ManageUsers::class)
            ->callAction('create', [
                'name' => 'Wanjiru Kamau',
                'email' => 'wanjiru@example.co.ke',
                'role' => UserRole::Accountant->value,
                'is_active' => true,
                'password' => 'a-strong-password',
            ])
            ->assertHasNoActionErrors();

        $user = User::query()->where('email', 'wanjiru@example.co.ke')->sole();
        $this->assertSame(UserRole::Accountant, $user->role);
        $this->assertTrue(Hash::check('a-strong-password', $user->password));
    }

    public function test_non_admin_cannot_manage_users(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::FinanceManager)->create())
            ->get('/admin/users')
            ->assertForbidden();
    }
}
