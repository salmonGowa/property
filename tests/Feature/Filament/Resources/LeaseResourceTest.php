<?php

namespace Tests\Feature\Filament\Resources;

use App\Enums\LeaseStatus;
use App\Enums\UnitType;
use App\Enums\UserRole;
use App\Filament\Resources\Leases\Pages\CreateLease;
use App\Filament\Resources\Leases\Pages\ViewLease;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SetsUpLedger;
use Tests\TestCase;

class LeaseResourceTest extends TestCase
{
    use LazilyRefreshDatabase, SetsUpLedger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLedger();
    }

    public function test_let_unit_link_prefills_the_unit_and_its_asking_terms(): void
    {
        $unit = Unit::factory()->ofType(UnitType::Office)->create(['market_rent' => '150000.00', 'service_charge' => '12000.00']);
        $this->actingAs(User::factory()->withRole(UserRole::PropertyManager)->create());

        Livewire::withQueryParams(['unit' => $unit->id])
            ->test(CreateLease::class)
            ->assertSchemaStateSet([
                'unit_id' => $unit->id,
                'rent_amount' => '150000.00',
                'service_charge' => '12000.00',
                'charge_vat' => true,
            ]);
    }

    public function test_property_manager_drafts_a_lease_through_the_wizard(): void
    {
        $tenant = Tenant::factory()->create();
        $unit = Unit::factory()->create();
        $this->actingAs(User::factory()->withRole(UserRole::PropertyManager)->create());

        Livewire::test(CreateLease::class)
            ->fillForm([
                'tenant_id' => $tenant->id,
                'unit_id' => $unit->id,
                'start_date' => '2026-11-01',
                'end_date' => '2027-10-31',
                'billing_frequency' => 'monthly',
                'due_day' => 5,
                'rent_amount' => '40000',
                'service_charge' => '3000',
                'deposit_amount' => '80000',
                'charge_vat' => false,
                'escalation_rate' => '5',
                'escalation_interval_months' => 12,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $lease = Lease::query()->sole();
        $this->assertSame(LeaseStatus::Draft, $lease->status);
        $this->assertSame('LSE-00001', $lease->reference);
        $this->assertSame('40000.00', $lease->rent_amount);
    }

    public function test_property_manager_activates_a_draft_lease(): void
    {
        $lease = $this->draftLease(['start_date' => '2026-10-01', 'deposit_amount' => '60000.00']);
        $this->actingAs(User::factory()->withRole(UserRole::PropertyManager)->create());

        Livewire::test(ViewLease::class, ['record' => $lease->id])
            ->callAction('activate')
            ->assertNotified('Lease activated');

        $this->assertSame(LeaseStatus::Active, $lease->refresh()->status);
        $this->assertSame(2, $lease->invoices()->count());
    }

    public function test_accountant_cannot_activate_or_terminate_leases(): void
    {
        $lease = $this->draftLease();
        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create());

        Livewire::test(ViewLease::class, ['record' => $lease->id])
            ->assertActionHidden('activate')
            ->assertActionHidden('terminate');
    }

    public function test_active_lease_terms_cannot_be_edited(): void
    {
        $lease = $this->draftLease();
        $lease->update(['status' => LeaseStatus::Active]);
        $this->actingAs(User::factory()->withRole(UserRole::PropertyManager)->create());

        $this->get("/admin/leases/{$lease->id}/edit")->assertForbidden();
    }
}
