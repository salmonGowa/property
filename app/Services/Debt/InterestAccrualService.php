<?php

namespace App\Services\Debt;

use App\Models\InterestAccrual;
use App\Models\Loan;
use App\Services\Ledger\InvalidJournalEntry;
use App\Services\Ledger\JournalLineData;
use App\Services\Ledger\LedgerAccounts;
use App\Services\Ledger\PostingService;
use App\Services\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Month-end interest accrual so the books show interest owed but not yet paid. Each accrual
 * (debit interest expense, credit accrued interest) is reversed on the first day of the next
 * month, and the full interest is expensed when the instalment is paid.
 */
class InterestAccrualService
{
    public function __construct(
        private PostingService $posting,
        private LedgerAccounts $accounts,
        private AmortisationSchedule $schedule,
    ) {}

    /**
     * Interest that has built up since the last instalment (or the first drawdown) to the date.
     *
     * @return array{days: int, amount: string}
     */
    public function accruedTo(Loan $loan, CarbonInterface $on): array
    {
        $from = $loan->instalments()->whereNotNull('loan_repayment_id')->get()->last()?->due_date ?? $loan->drawdowns()->first()?->drawn_on;

        if ($from === null || $from->gte($on)) {
            return ['days' => 0, 'amount' => '0.00'];
        }

        $days = (int) $from->diffInDays($on);

        return ['days' => $days, 'amount' => $this->schedule->interestFor($loan->outstandingPrincipal(), (string) $loan->interest_rate, $days)];
    }

    /**
     * Accrue every active loan's interest at the month end. Loans already accrued for the month are skipped.
     *
     * @return array{accruals: list<InterestAccrual>, failures: array<string, string>}
     */
    public function accrue(CarbonInterface|string $monthEnd): array
    {
        $monthEnd = Carbon::parse($monthEnd)->endOfMonth()->startOfDay();
        $accruals = [];
        $failures = [];

        foreach (Loan::query()->active()->with('lender')->get() as $loan) {
            if ($loan->accruals()->whereDate('period_end', $monthEnd)->exists()) {
                continue;
            }

            ['days' => $days, 'amount' => $amount] = $this->accruedTo($loan, $monthEnd);

            if (! Money::isPositive($amount)) {
                continue;
            }

            try {
                $accruals[] = DB::transaction(function () use ($loan, $monthEnd, $days, $amount): InterestAccrual {
                    $entry = $this->posting->post($monthEnd, "Interest accrued {$loan->reference} {$loan->lender->name}, {$days} days", [
                        JournalLineData::debit($this->accounts->get('interest_expense'), $amount, "{$loan->reference} accrued interest"),
                        JournalLineData::credit($this->accounts->get('accrued_interest'), $amount, $loan->lender->name),
                    ], $loan->reference, $loan);

                    $reversal = $this->posting->reverse($entry, $monthEnd->copy()->addDay(), "Reverse {$monthEnd->format('M Y')} interest accrual");

                    return $loan->accruals()->create([
                        'period_end' => $monthEnd,
                        'days' => $days,
                        'amount' => $amount,
                        'journal_entry_id' => $entry->id,
                        'reversal_journal_entry_id' => $reversal->id,
                    ]);
                });
            } catch (InvalidJournalEntry $exception) {
                $failures[$loan->reference] = $exception->getMessage();
            }
        }

        return ['accruals' => $accruals, 'failures' => $failures];
    }
}
