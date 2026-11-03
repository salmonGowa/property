<?php

namespace Database\Factories;

use App\Enums\KenyaCounty;
use App\Enums\PropertyType;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('???-##')),
            'name' => fake()->lastName().' '.fake()->randomElement(['Apartments', 'Plaza', 'Court', 'Towers', 'Business Park']),
            'type' => PropertyType::Residential,
            'county' => KenyaCounty::Nairobi,
            'town' => 'Nairobi',
            'is_active' => true,
        ];
    }
}
