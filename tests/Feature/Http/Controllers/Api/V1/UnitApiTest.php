<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\LeaseStatus;
use App\Enums\UnitStatus;
use App\Enums\UserRole;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class UnitApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_lists_a_propertys_units_with_the_current_tenant(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::Auditor)->create());

        $property = Property::factory()->create();
        $unit = Unit::factory()->for($property)->create(['code' => 'A-101', 'status' => UnitStatus::Occupied]);
        Lease::factory()->create([
            'unit_id' => $unit->id,
            'tenant_id' => Tenant::factory()->create(['name' => 'Achieng Otieno'])->id,
            'status' => LeaseStatus::Active,
        ]);
        Unit::factory()->create();

        $this->getJson("/api/v1/properties/{$property->id}/units")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'A-101')
            ->assertJsonPath('data.0.status.label', 'Occupied')
            ->assertJsonPath('data.0.activeLease.tenant.name', 'Achieng Otieno');
    }

    public function test_filters_units_by_status_and_tenant_name(): void
    {
        $this->actingAs(User::factory()->create());

        $property = Property::factory()->create();
        $occupied = Unit::factory()->for($property)->create(['code' => 'A-101', 'status' => UnitStatus::Occupied]);
        Unit::factory()->for($property)->create(['code' => 'A-102', 'status' => UnitStatus::Vacant]);
        Lease::factory()->create([
            'unit_id' => $occupied->id,
            'tenant_id' => Tenant::factory()->create(['name' => 'Achieng Otieno'])->id,
            'status' => LeaseStatus::Active,
        ]);

        $this->getJson("/api/v1/properties/{$property->id}/units?status=vacant")->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'A-102');
        $this->getJson("/api/v1/properties/{$property->id}/units?search=achieng")->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'A-101');
    }

    public function test_property_manager_adds_a_unit(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::PropertyManager)->create());
        $property = Property::factory()->create();

        $this->postJson("/api/v1/properties/{$property->id}/units", [
            'code' => 'a-101',
            'type' => 'apartment',
            'bedrooms' => 2,
            'market_rent' => '35000',
        ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'A-101')
            ->assertJsonPath('data.status.value', 'vacant');

        $this->assertDatabaseHas('units', ['property_id' => $property->id, 'code' => 'A-101', 'market_rent' => '35000.00', 'status' => 'vacant']);
    }

    public function test_a_blank_service_charge_is_saved_as_zero(): void
    {
        $this->actingAs(User::factory()->create());
        $property = Property::factory()->create();

        $this->postJson("/api/v1/properties/{$property->id}/units", ['code' => 'A-101', 'type' => 'shop', 'market_rent' => '80000', 'service_charge' => null])
            ->assertCreated()
            ->assertJsonPath('data.serviceCharge', '0.00');
    }

    public function test_unit_numbers_are_unique_within_a_property_only(): void
    {
        $this->actingAs(User::factory()->create());
        $property = Property::factory()->create();
        Unit::factory()->for($property)->create(['code' => 'A-101']);
        $otherProperty = Property::factory()->create();

        $this->postJson("/api/v1/properties/{$property->id}/units", ['code' => 'A-101', 'type' => 'apartment', 'market_rent' => 1])
            ->assertJsonValidationErrors(['code' => 'This property already has a unit with that number.']);

        $this->postJson("/api/v1/properties/{$otherProperty->id}/units", ['code' => 'A-101', 'type' => 'apartment', 'market_rent' => 1])
            ->assertCreated();
    }

    public function test_units_cannot_be_marked_occupied_by_hand(): void
    {
        $this->actingAs(User::factory()->create());
        $property = Property::factory()->create();

        $this->postJson("/api/v1/properties/{$property->id}/units", ['code' => 'A-101', 'type' => 'apartment', 'market_rent' => 1, 'status' => 'occupied'])
            ->assertJsonValidationErrors(['status' => 'Units become occupied only when a lease is activated.']);
    }

    public function test_an_occupied_units_status_cannot_be_changed(): void
    {
        $this->actingAs(User::factory()->create());
        $unit = Unit::factory()->create(['code' => 'A-101', 'status' => UnitStatus::Occupied]);

        $this->putJson("/api/v1/units/{$unit->id}", ['code' => 'A-101', 'type' => 'apartment', 'market_rent' => 1, 'status' => 'vacant'])
            ->assertJsonValidationErrors(['status' => 'Occupied by an active lease. Terminate the lease to change this.']);

        $this->putJson("/api/v1/units/{$unit->id}", ['code' => 'A-101', 'type' => 'apartment', 'market_rent' => '40000'])
            ->assertOk();

        $this->assertDatabaseHas('units', ['id' => $unit->id, 'status' => 'occupied', 'market_rent' => '40000.00']);
    }

    public function test_accountants_cannot_add_units(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create());
        $property = Property::factory()->create();

        $this->postJson("/api/v1/properties/{$property->id}/units", ['code' => 'A-101', 'type' => 'apartment', 'market_rent' => 1])
            ->assertForbidden();
    }

    public function test_admins_delete_units_that_never_had_a_lease(): void
    {
        $this->actingAs(User::factory()->create());
        $unit = Unit::factory()->create();
        $leased = Unit::factory()->create();
        Lease::factory()->create(['unit_id' => $leased->id]);

        $this->deleteJson("/api/v1/units/{$unit->id}")->assertNoContent();
        $this->deleteJson("/api/v1/units/{$leased->id}")->assertForbidden();

        $this->assertModelMissing($unit);
        $this->assertModelExists($leased);
    }
}
