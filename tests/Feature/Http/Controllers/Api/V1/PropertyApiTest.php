<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\LeaseStatus;
use App\Enums\UnitStatus;
use App\Enums\UserRole;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PropertyApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_cannot_list_properties(): void
    {
        $this->getJson('/api/v1/properties')->assertUnauthorized();
    }

    public function test_roles_without_property_access_are_refused(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::GateOfficer)->create())
            ->getJson('/api/v1/properties')
            ->assertForbidden();
    }

    public function test_lists_active_properties_with_occupancy_by_default(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create());

        $property = Property::factory()->create(['name' => 'Kilimani Heights']);
        Unit::factory()->for($property)->create(['status' => UnitStatus::Occupied]);
        Unit::factory()->for($property)->create(['status' => UnitStatus::Vacant]);
        Property::factory()->create(['is_active' => false]);

        $this->getJson('/api/v1/properties')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Kilimani Heights')
            ->assertJsonPath('data.0.unitsCount', 2)
            ->assertJsonPath('data.0.occupancyRate', 50)
            ->assertJsonPath('data.0.can', ['update' => false, 'delete' => false])
            ->assertJsonPath('meta.total', 1);
    }

    public function test_filters_by_search_term_and_status(): void
    {
        $this->actingAs(User::factory()->create());

        Property::factory()->create(['name' => 'Kilimani Heights', 'code' => 'KIL-01']);
        Property::factory()->create(['name' => 'Westlands Plaza', 'code' => 'WES-01', 'is_active' => false]);

        $this->getJson('/api/v1/properties?search=kil')->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'KIL-01');
        $this->getJson('/api/v1/properties?status=inactive')->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'WES-01');
        $this->getJson('/api/v1/properties?status=all')->assertJsonCount(2, 'data');
    }

    public function test_sorts_by_an_allowed_column_and_rejects_others(): void
    {
        $this->actingAs(User::factory()->create());

        Property::factory()->create(['name' => 'Alpha Court']);
        Property::factory()->create(['name' => 'Zeta Towers']);

        $this->getJson('/api/v1/properties?sort=-name')->assertJsonPath('data.0.name', 'Zeta Towers');
        $this->getJson('/api/v1/properties?sort=password')->assertJsonValidationErrors('sort');
    }

    public function test_property_manager_creates_a_property_with_a_normalised_code(): void
    {
        $manager = User::factory()->withRole(UserRole::PropertyManager)->create();
        $this->actingAs($manager);

        $this->postJson('/api/v1/properties', [
            'name' => 'Kilimani Heights',
            'code' => ' kil-01 ',
            'type' => 'residential',
            'county' => 'nairobi',
            'town' => 'Kilimani',
            'manager_id' => $manager->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'KIL-01')
            ->assertJsonPath('data.location', 'Kilimani, Nairobi')
            ->assertJsonPath('data.manager.id', $manager->id)
            ->assertJsonPath('data.stats.units', 0);

        $this->assertDatabaseHas('properties', ['code' => 'KIL-01', 'name' => 'Kilimani Heights', 'manager_id' => $manager->id]);
    }

    public function test_creating_requires_the_core_fields(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/v1/properties', [])
            ->assertJsonValidationErrors(['name', 'code', 'type', 'county']);
    }

    public function test_property_codes_must_be_unique(): void
    {
        $this->actingAs(User::factory()->create());
        Property::factory()->create(['code' => 'KIL-01']);

        $this->postJson('/api/v1/properties', ['name' => 'X', 'code' => 'kil-01', 'type' => 'residential', 'county' => 'nairobi'])
            ->assertJsonValidationErrors(['code' => 'The code has already been taken.']);
    }

    public function test_only_property_managers_or_admins_can_be_assigned_as_manager(): void
    {
        $this->actingAs(User::factory()->create());
        $accountant = User::factory()->withRole(UserRole::Accountant)->create();

        $this->postJson('/api/v1/properties', ['name' => 'X', 'code' => 'X-01', 'type' => 'residential', 'county' => 'nairobi', 'manager_id' => $accountant->id])
            ->assertJsonValidationErrors(['manager_id' => 'Choose an active property manager or administrator.']);
    }

    public function test_accountants_cannot_create_properties(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create());

        $this->postJson('/api/v1/properties', ['name' => 'X', 'code' => 'X-01', 'type' => 'residential', 'county' => 'nairobi'])
            ->assertForbidden();

        $this->assertDatabaseCount('properties', 0);
    }

    public function test_shows_occupancy_and_the_rent_roll_of_active_leases(): void
    {
        $this->actingAs(User::factory()->create());

        $property = Property::factory()->create();
        $occupied = Unit::factory()->for($property)->create(['status' => UnitStatus::Occupied]);
        Unit::factory()->for($property)->create(['status' => UnitStatus::Vacant]);
        Lease::factory()->create(['unit_id' => $occupied->id, 'status' => LeaseStatus::Active, 'rent_amount' => '45000.00']);
        Lease::factory()->create(['unit_id' => $occupied->id, 'status' => LeaseStatus::Draft, 'rent_amount' => '99000.00']);

        $this->getJson("/api/v1/properties/{$property->id}")
            ->assertOk()
            ->assertJsonPath('data.stats', [
                'units' => 2,
                'occupied' => 1,
                'vacant' => 1,
                'occupancyRate' => 50,
                'monthlyRentRoll' => '45000.00',
            ]);
    }

    public function test_updates_a_property_keeping_its_own_code(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::PropertyManager)->create());
        $property = Property::factory()->create(['code' => 'KIL-01']);

        $this->putJson("/api/v1/properties/{$property->id}", [
            'name' => 'Kilimani Heights II',
            'code' => 'KIL-01',
            'type' => 'mixed_use',
            'county' => 'kiambu',
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.type.label', 'Mixed Use');

        $this->assertDatabaseHas('properties', ['id' => $property->id, 'name' => 'Kilimani Heights II', 'county' => 'kiambu', 'is_active' => false]);
    }

    public function test_admins_delete_properties_without_units(): void
    {
        $this->actingAs(User::factory()->create());
        $property = Property::factory()->create();

        $this->deleteJson("/api/v1/properties/{$property->id}")->assertNoContent();

        $this->assertModelMissing($property);
    }

    public function test_properties_with_units_cannot_be_deleted(): void
    {
        $this->actingAs(User::factory()->create());
        $property = Property::factory()->create();
        Unit::factory()->for($property)->create();

        $this->deleteJson("/api/v1/properties/{$property->id}")->assertForbidden();

        $this->assertModelExists($property);
    }

    public function test_uploading_a_photo_replaces_the_previous_one(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->withRole(UserRole::PropertyManager)->create());
        $property = Property::factory()->create(['photo_path' => 'properties/old.jpg']);
        Storage::disk('public')->put('properties/old.jpg', 'old');

        $response = $this->postJson("/api/v1/properties/{$property->id}/photo", ['photo' => UploadedFile::fake()->image('front.jpg')])
            ->assertOk();

        $path = $property->refresh()->photo_path;
        Storage::disk('public')->assertExists($path);
        Storage::disk('public')->assertMissing('properties/old.jpg');
        $response->assertJsonPath('data.photoUrl', Storage::disk('public')->url($path));
    }

    public function test_photos_must_be_images(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create());
        $property = Property::factory()->create();

        $this->postJson("/api/v1/properties/{$property->id}/photo", ['photo' => UploadedFile::fake()->create('deed.pdf', 100, 'application/pdf')])
            ->assertJsonValidationErrors('photo');
    }

    public function test_removing_a_photo_deletes_the_file(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create());
        Storage::disk('public')->put('properties/old.jpg', 'old');
        $property = Property::factory()->create(['photo_path' => 'properties/old.jpg']);

        $this->deleteJson("/api/v1/properties/{$property->id}/photo")
            ->assertOk()
            ->assertJsonPath('data.photoUrl', null);

        Storage::disk('public')->assertMissing('properties/old.jpg');
    }
}
