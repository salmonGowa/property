<?php

namespace Database\Factories;

use App\Enums\UnitStatus;
use App\Enums\UnitType;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'code' => strtoupper(fake()->unique()->bothify('?-###')),
            'type' => UnitType::Apartment,
            'status' => UnitStatus::Vacant,
            'bedrooms' => 2,
            'market_rent' => '35000.00',
            'service_charge' => '3000.00',
        ];
    }

    public function ofType(UnitType $type): static
    {
        return $this->state(fn (array $attributes) => ['type' => $type]);
    }
}
