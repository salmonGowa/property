<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class LookupApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_returns_labelled_options_and_only_active_managers(): void
    {
        $admin = User::factory()->create(['name' => 'Admin']);
        $this->actingAs($admin);
        $manager = User::factory()->withRole(UserRole::PropertyManager)->create(['name' => 'Baraka']);
        User::factory()->withRole(UserRole::PropertyManager)->inactive()->create();
        User::factory()->withRole(UserRole::Accountant)->create();

        $this->getJson('/api/v1/lookups')
            ->assertOk()
            ->assertJsonPath('data.propertyTypes.2', ['value' => 'mixed_use', 'label' => 'Mixed Use'])
            ->assertJsonPath('data.unitStatuses.0', ['value' => 'vacant', 'label' => 'Vacant', 'color' => 'success'])
            ->assertJsonPath('data.propertyManagers', [
                ['id' => $admin->id, 'name' => 'Admin'],
                ['id' => $manager->id, 'name' => 'Baraka'],
            ]);
    }
}
