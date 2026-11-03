<?php

namespace App\Console\Commands;

use App\Services\Debt\CovenantService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('loans:test-covenants {--date= : Test as at this date (default: today)}')]
#[Description('Measure DSCR, LTV and debt yield covenants on active loans and record the results')]
class TestLoanCovenants extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CovenantService $covenants): int
    {
        $tests = $covenants->testAll(Carbon::parse($this->option('date') ?? today()));

        $this->table(['Loan', 'Covenant', 'Actual', 'Result'], $tests->map(fn ($test): array => [
            $test->covenant->loan->reference,
            $test->covenant->requirement(),
            $test->covenant->metric->format($test->actual),
            match ($test->passed) {
                true => 'Met',
                false => 'BREACHED',
                null => 'Not measurable',
            },
        ])->all());

        $breaches = $tests->where('passed', false)->count();

        if ($breaches > 0) {
            $this->warn("{$breaches} covenant(s) breached.");
        }

        return self::SUCCESS;
    }
}
