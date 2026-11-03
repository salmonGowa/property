<?php

namespace Database\Factories;

use App\Enums\ChargeType;
use App\Enums\PenaltyCapType;
use App\Enums\PenaltyMethod;
use App\Enums\PenaltyRecurrence;
use App\Models\PenaltyRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PenaltyRule>
 */
class PenaltyRuleFactory extends Factory
{
    /**
     * Five percent of overdue rent and service charge, once, five days after the due date.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Standard late payment',
            'method' => PenaltyMethod::Percentage,
            'rate' => '5.0000',
            'grace_days' => 5,
            'recurrence' => PenaltyRecurrence::Once,
            'recurrence_days' => null,
            'charge_types' => [ChargeType::Rent->value, ChargeType::ServiceCharge->value],
            'minimum_overdue' => '0.00',
            'cap_type' => PenaltyCapType::None,
            'cap_value' => null,
            'effective_from' => '2026-01-01',
            'is_default' => false,
            'is_active' => true,
        ];
    }

    public function companyDefault(): static
    {
        return $this->state(fn (array $attributes) => ['is_default' => true]);
    }
}
