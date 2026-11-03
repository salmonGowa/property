<?php

namespace Tests\Feature\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\Tenants\Pages\CreateTenant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TenantResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_registering_a_tenant_assigns_an_account_number_and_normalises_details(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::PropertyManager)->create());

        Livewire::test(CreateTenant::class)
            ->fillForm([
                'type' => 'individual',
                'name' => 'Achieng Otieno',
                'phone' => '0712 345 678',
                'kra_pin' => 'a001234567z',
                'id_number' => '23456789',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $tenant = Tenant::query()->sole();
        $this->assertSame('T00001', $tenant->account_number);
        $this->assertSame('+254712345678', $tenant->phone);
        $this->assertSame('A001234567Z', $tenant->kra_pin);
        $this->assertNotSame('23456789', $tenant->getRawOriginal('id_number'));
    }

    public function test_rejects_an_invalid_kra_pin_and_phone(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::PropertyManager)->create());

        Livewire::test(CreateTenant::class)
            ->fillForm(['type' => 'individual', 'name' => 'X', 'phone' => '12345', 'kra_pin' => 'ABC'])
            ->call('create')
            ->assertHasFormErrors(['phone' => 'regex', 'kra_pin' => 'regex']);
    }

    public function test_accountant_can_view_but_not_register_tenants(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create());

        $this->get('/admin/tenants')->assertOk();
        $this->get('/admin/tenants/create')->assertForbidden();
    }

    public function test_gate_officer_cannot_see_tenants(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::GateOfficer)->create())
            ->get('/admin/tenants')
            ->assertForbidden();
    }
}
