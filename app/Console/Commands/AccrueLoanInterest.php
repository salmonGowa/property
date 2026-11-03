<?php

namespace App\Console\Commands;

use App\Services\Debt\InterestAccrualService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

#[Signature('loans:accrue-interest {--month= : Any date in the month to accrue (default: last month)}')]
#[Description('Accrue interest owed on loans at the month end, reversed on the 1st of the next month')]
class AccrueLoanInterest extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(InterestAccrualService $accruals): int
    {
        $monthEnd = Carbon::parse($this->option('month') ?? today()->subMonthNoOverflow())->endOfMonth();
        ['accruals' => $posted, 'failures' => $failures] = $accruals->accrue($monthEnd);

        foreach ($posted as $accrual) {
            $this->line("{$accrual->loan->reference}: KES ".Number::format((float) $accrual->amount, 2)." for {$accrual->days} days");
        }

        $this->info(count($posted).' accrual(s) posted for '.$monthEnd->format('F Y').'.');

        foreach ($failures as $loan => $message) {
            $this->warn("{$loan}: {$message}");
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }
}
