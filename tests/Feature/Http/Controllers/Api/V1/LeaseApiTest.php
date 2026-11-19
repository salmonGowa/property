<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\LeaseStatus;
use App\Enums\UnitStatus;
use App\Enums\UserRole;
use App\Models\Lease;
use App\Models\PenaltyRule;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class LeaseApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_roles_without_property_access_are_refused(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::GateOfficer)->create())
            ->getJson('/api/v1/leases')
            ->assertForbidden();
    }

    public function test_lists_active_leases_by_default_with_tab_counts(): void
    {
        $this->travelTo('2026-10-15');
        $this->actingAs(User::factory()->withRole(UserRole::Auditor)->create());

        $active = Lease::factory()->create(['status' => LeaseStatus::Active, 'end_date' => '2027-09-30']);
        Lease::factory()->create(['status' => LeaseStatus::Active, 'end_date' => '2026-11-30']);
        Lease::factory()->create(['status' => LeaseStatus::Draft]);
        Lease::factory()->create(['status' => LeaseStatus::Terminated]);

        $this->getJson('/api/v1/leases?sort=end_date')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.endsSoon', true)
            ->assertJsonPath('data.1.id', $active->id)
            ->assertJsonPath('data.1.endsSoon', false)
            ->assertJsonPath('data.1.unit.fullCode', $active->unit->fullCode())
            ->assertJsonPath('counts', ['active' => 2, 'expiring' => 1, 'draft' => 1]);

        $this->getJson('/api/v1/leases?status=expiring')->assertJsonCount(1, 'data')->assertJsonPath('data.0.endDate', '2026-11-30');
        $this->getJson('/api/v1/leases?status=all')->assertJsonCount(4, 'data');
    }

    public function test_searches_by_tenant_or_unit_and_filters_by_property(): void
    {
        $this->actingAs(User::factory()->create());

        $property = Property::factory()->create();
        $lease = Lease::factory()->create([
            'status' => LeaseStatus::Active,
            'tenant_id' => Tenant::factory()->create(['name' => 'Achieng Otieno']),
            'unit_id' => Unit::factory()->for($property)->create(['code' => 'B-204']),
        ]);
        Lease::factory()->create(['status' => LeaseStatus::Active]);

        $this->getJson('/api/v1/leases?search=achieng')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $lease->id);
        $this->getJson('/api/v1/leases?search=B-204')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $lease->id);
        $this->getJson("/api/v1/leases?property_id={$property->id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $lease->id);
        $this->getJson("/api/v1/leases?tenant_id={$lease->tenant_id}&status=all")->assertJsonCount(1, 'data');
    }

    public function test_property_manager_drafts_a_lease(): void
    {
        $user = User::factory()->withRole(UserRole::PropertyManager)->create();
        $tenant = Tenant::factory()->create();
        $unit = Unit::factory()->create(['status' => UnitStatus::Vacant]);

        $this->actingAs($user)->postJson('/api/v1/leases', [
            ...$this->terms($tenant, $unit),
            'service_charge' => '',
            'penalties_exempt' => true,
            'penalty_rule_id' => PenaltyRule::factory()->create()->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'draft')
            ->assertJsonPath('data.details.can.activate', true)
            ->assertJsonPath('data.details.penalty.source', 'Exempt on this lease')
            ->assertJsonPath('data.createdBy', $user->name);

        $lease = Lease::query()->sole();
        $this->assertNotEmpty($lease->reference);
        $this->assertSame('0.00', $lease->service_charge);
        $this->assertNull($lease->penalty_rule_id);
        $this->assertSame(UnitStatus::Vacant, $unit->refresh()->status);
    }

    public function test_refuses_let_units_inactive_tenants_and_backwards_dates(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/v1/leases', [
            ...$this->terms(Tenant::factory()->create(['is_active' => false]), Unit::factory()->create(['status' => UnitStatus::Occupied])),
            'start_date' => '2026-11-01',
            'end_date' => '2026-10-01',
            'due_day' => 30,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'tenant_id' => 'Choose an active tenant.',
                'unit_id' => 'This unit is not available',
                'end_date' => 'The end date must be after the start date.',
                'due_day' => 'Rent must fall due between the 1st and the 28th.',
            ]);
    }

    public function test_a_draft_keeps_its_own_unit_when_edited(): void
    {
        $this->actingAs(User::factory()->create());
        $lease = Lease::factory()->create();
        $lease->unit->update(['status' => UnitStatus::Maintenance]);

        $this->putJson("/api/v1/leases/{$lease->id}", [...$this->terms($lease->tenant, $lease->unit), 'rent_amount' => '42000'])
            ->assertOk()
            ->assertJsonPath('data.rentAmount', '42000.00');
    }

    public function test_active_leases_cannot_be_edited_or_deleted(): void
    {
        $this->actingAs(User::factory()->create());
        $lease = Lease::factory()->create(['status' => LeaseStatus::Active]);

        $this->putJson("/api/v1/leases/{$lease->id}", $this->terms($lease->tenant, $lease->unit))->assertForbidden();
        $this->deleteJson("/api/v1/leases/{$lease->id}")->assertForbidden();
    }

    public function test_a_draft_can_be_discarded(): void
    {
        $this->actingAs(User::factory()->create());
        $lease = Lease::factory()->create();

        $this->deleteJson("/api/v1/leases/{$lease->id}")->assertNoContent();

        $this->assertModelMissing($lease);
    }

    public function test_detail_explains_which_penalty_rule_applies(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create());
        $rule = PenaltyRule::factory()->create(['name' => 'Westlands 5%']);
        $property = Property::factory()->create(['name' => 'Westlands Court', 'penalty_rule_id' => $rule->id]);
        $lease = Lease::factory()->create(['unit_id' => Unit::factory()->for($property)]);

        $this->getJson("/api/v1/leases/{$lease->id}")
            ->assertOk()
            ->assertJsonPath('data.details.penalty.name', 'Westlands 5%')
            ->assertJsonPath('data.details.penalty.source', 'Inherited from Westlands Court')
            ->assertJsonPath('data.details.depositHeld', '0.00')
            ->assertJsonPath('data.details.can.activate', false)
            ->assertJsonPath('data.can.update', false);
    }

    public function test_lettable_units_are_vacant_or_reserved_plus_the_one_being_edited(): void
    {
        $this->actingAs(User::factory()->create());
        $vacant = Unit::factory()->create(['status' => UnitStatus::Vacant]);
        $reserved = Unit::factory()->create(['status' => UnitStatus::Reserved]);
        $occupied = Unit::factory()->create(['status' => UnitStatus::Occupied]);

        $this->getJson('/api/v1/lettable-units')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $vacant->id, 'fullCode' => $vacant->fullCode()])
            ->assertJsonFragment(['id' => $reserved->id]);

        $this->getJson("/api/v1/lettable-units?include={$occupied->id}")->assertJsonCount(3, 'data');
    }

    /**
     * @return array<string, mixed>
     */
    private function terms(Tenant $tenant, Unit $unit): array
    {
        return [
            'tenant_id' => $tenant->id,
            'unit_id' => $unit->id,
            'start_date' => '2026-11-01',
            'end_date' => '2027-10-31',
            'billing_frequency' => 'monthly',
            'due_day' => 5,
            'rent_amount' => '35000',
            'deposit_amount' => '70000',
            'charge_vat' => false,
            'escalation_rate' => '5',
            'escalation_interval_months' => 12,
        ];
    }
}
