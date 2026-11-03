<?php

namespace Database\Factories;

use App\Enums\BillingFrequency;
use App\Enums\LeaseStatus;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Draft leases. Activate them through LeaseService so the unit and billing follow.
 *
 * @extends Factory<Lease>
 */
class LeaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => 'LSE-'.fake()->unique()->numerify('9####'),
            'tenant_id' => Tenant::factory(),
            'unit_id' => Unit::factory(),
            'status' => LeaseStatus::Draft,
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->startOfMonth()->addYear()->subDay()->toDateString(),
            'billing_frequency' => BillingFrequency::Monthly,
            'rent_amount' => '30000.00',
            'service_charge' => '0.00',
            'deposit_amount' => '0.00',
            'charge_vat' => false,
            'due_day' => 5,
            'escalation_rate' => '0.00',
            'escalation_interval_months' => 12,
        ];
    }
}
