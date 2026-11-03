<?php

namespace App\Console\Commands;

use App\Services\Ledger\FiscalPeriodService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ledger:open-year {year : The calendar year, e.g. 2026}')]
#[Description('Create the twelve monthly fiscal periods for a year (existing periods are left untouched)')]
class OpenFiscalYear extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(FiscalPeriodService $fiscalPeriods): int
    {
        $year = (int) $this->argument('year');

        if ($year < 2000 || $year > 2100) {
            $this->error('Enter a four-digit year between 2000 and 2100.');

            return self::FAILURE;
        }

        $periods = $fiscalPeriods->openYear($year);

        $this->info("Fiscal year {$year} ready: {$periods->count()} monthly periods.");

        return self::SUCCESS;
    }
}
