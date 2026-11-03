<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\PropertyValuation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyValuation>
 */
class PropertyValuationFactory extends Factory
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
            'valued_on' => today()->subMonths(3),
            'market_value' => '200000000.00',
            'valuer' => fake()->lastName().' Valuers',
        ];
    }
}
