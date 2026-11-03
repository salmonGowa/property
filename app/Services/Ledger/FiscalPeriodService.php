<?php

namespace App\Services\Ledger;

use App\Enums\FiscalPeriodStatus;
use App\Models\FiscalPeriod;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FiscalPeriodService
{
    /**
     * Create the twelve monthly periods of a calendar year, skipping any that already exist.
     *
     * @return Collection<int, FiscalPeriod>
     */
    public function openYear(int $year): Collection
    {
        return collect(range(1, 12))->map(function (int $month) use ($year): FiscalPeriod {
            $start = Carbon::create($year, $month, 1);

            return FiscalPeriod::query()->firstOrCreate(
                ['starts_on' => $start->toDateString()],
                [
                    'name' => $start->format('M Y'),
                    'ends_on' => $start->copy()->endOfMonth()->toDateString(),
                    'status' => FiscalPeriodStatus::Open,
                ],
            );
        });
    }

    public function close(FiscalPeriod $period, User $closedBy): void
    {
        $period->update([
            'status' => FiscalPeriodStatus::Closed,
            'closed_at' => now(),
            'closed_by' => $closedBy->id,
        ]);
    }

    public function reopen(FiscalPeriod $period): void
    {
        $period->update([
            'status' => FiscalPeriodStatus::Open,
            'closed_at' => null,
            'closed_by' => null,
        ]);
    }
}
