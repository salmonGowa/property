<?php

namespace Database\Factories;

use App\Enums\FiscalPeriodStatus;
use App\Models\FiscalPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<FiscalPeriod>
 */
class FiscalPeriodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return $this->definitionFor(now());
    }

    /**
     * The calendar month containing the given date.
     */
    public function forMonth(Carbon|string $date): static
    {
        $date = Carbon::parse($date);

        return $this->state(fn (array $attributes) => $this->definitionFor($date));
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => FiscalPeriodStatus::Closed,
            'closed_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function definitionFor(Carbon $date): array
    {
        return [
            'name' => $date->format('M Y'),
            'starts_on' => $date->copy()->startOfMonth()->toDateString(),
            'ends_on' => $date->copy()->endOfMonth()->toDateString(),
            'status' => FiscalPeriodStatus::Open,
        ];
    }
}
