<?php

namespace Database\Factories;

use App\Enums\BillingFrequency;
use App\Enums\WorkOrderCategory;
use App\Enums\WorkOrderPriority;
use App\Models\MaintenanceSchedule;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceSchedule>
 */
class MaintenanceScheduleFactory extends Factory
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
            'title' => 'Lift servicing',
            'category' => WorkOrderCategory::Lifts,
            'priority' => WorkOrderPriority::Normal,
            'frequency' => BillingFrequency::Quarterly,
            'next_due_on' => today()->addDays(30),
            'lead_days' => 7,
            'is_active' => true,
        ];
    }
}
