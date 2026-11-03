<?php

namespace Database\Factories;

use App\Enums\WorkOrderCategory;
use App\Enums\WorkOrderPriority;
use App\Enums\WorkOrderStatus;
use App\Models\Property;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkOrder>
 */
class WorkOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => 'WO-'.fake()->unique()->numerify('9#####'),
            'property_id' => Property::factory(),
            'title' => fake()->randomElement(['Leaking kitchen tap', 'Faulty socket in bedroom', 'Broken door lock', 'Blocked drain']),
            'category' => WorkOrderCategory::General,
            'priority' => WorkOrderPriority::Normal,
            'status' => WorkOrderStatus::Open,
            'reported_at' => now(),
            'due_on' => today()->addDays(7),
        ];
    }
}
