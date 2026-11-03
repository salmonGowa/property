<?php

namespace Database\Factories;

use App\Models\Lender;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lender>
 */
class LenderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['KCB Bank Kenya', 'Equity Bank Kenya', 'NCBA Bank Kenya', 'Co-operative Bank of Kenya', 'Stanbic Bank Kenya']),
            'branch' => 'Corporate Banking',
            'contact_person' => fake()->name(),
            'phone' => '07'.fake()->numerify('########'),
            'email' => fake()->safeEmail(),
            'interest_withholding_rate' => '0.00',
            'is_active' => true,
        ];
    }
}
