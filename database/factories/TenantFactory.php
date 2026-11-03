<?php

namespace Database\Factories;

use App\Enums\TenantType;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => TenantType::Individual,
            'name' => fake()->name(),
            'id_number' => (string) fake()->numerify('########'),
            'kra_pin' => 'A'.fake()->numerify('#########').'Z',
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+2547'.fake()->numerify('########'),
            'is_active' => true,
        ];
    }

    public function company(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => TenantType::Company,
            'name' => fake()->company().' Limited',
            'id_number' => null,
            'registration_number' => 'PVT-'.strtoupper(fake()->bothify('??####')),
            'kra_pin' => 'P'.fake()->numerify('#########').'Q',
            'contact_person' => fake()->name(),
        ]);
    }
}
