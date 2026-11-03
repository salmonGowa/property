<?php

namespace App\Filament\Widgets;

use App\Models\LoanInstalment;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

/**
 * Principal falling due by period, from the unpaid parts of every active loan's schedule.
 */
class MaturityProfile extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Principal falling due';

    protected function getStats(): array
    {
        $bands = [
            'Within 1 year' => [null, today()->addYear()],
            '1-3 years' => [today()->addYear(), today()->addYears(3)],
            '3-5 years' => [today()->addYears(3), today()->addYears(5)],
            'Over 5 years' => [today()->addYears(5), null],
        ];
        $amounts = collect($bands)->map(fn (array $band): float => (float) LoanInstalment::query()
            ->whereNull('loan_repayment_id')
            ->whereHas('loan', fn (Builder $query) => $query->active())
            ->when($band[0], fn (Builder $query) => $query->whereDate('due_date', '>', $band[0]))
            ->when($band[1], fn (Builder $query) => $query->whereDate('due_date', '<=', $band[1]))
            ->sum('principal'));
        $total = max($amounts->sum(), 1);

        return $amounts->map(fn (float $amount, string $label): Stat => Stat::make($label, 'KES '.Number::abbreviate($amount, 1))
            ->description(round($amount / $total * 100).'% of debt'))->values()->all();
    }
}
