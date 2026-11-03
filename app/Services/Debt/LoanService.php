<?php

namespace App\Services\Debt;

use App\Enums\InterestRateType;
use App\Enums\LoanStatus;
use App\Enums\LoanType;
use App\Enums\RepaymentType;
use App\Models\Account;
use App\Models\Loan;
use App\Models\LoanDrawdown;
use App\Models\LoanInstalment;
use App\Models\LoanRepayment;
use App\Models\User;
use App\Services\Ledger\InvalidJournalEntry;
use App\Services\Ledger\JournalLineData;
use App\Services\Ledger\LedgerAccounts;
use App\Services\Ledger\PostingService;
use App\Services\Money;
use App\Services\Numbering\DocumentNumber;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Sets up borrowings and records their life: drawdowns credit the loan account, repayments
 * split into principal (debit the loan) and interest (debit interest expense), and the
 * unpaid part of the schedule is rebuilt whenever the balance, rate or terms change.
 */
class LoanService
{
    public function __construct(
        private PostingService $posting,
        private LedgerAccounts $accounts,
        private DocumentNumber $numbers,
        private AmortisationSchedule $schedule,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<int|string>  $propertyIds  Properties charged as security
     *
     * @throws LoanActionRefused
     */
    public function create(array $attributes, array $propertyIds = [], ?User $by = null): Loan
    {
        $attributes = $this->prepare($attributes);

        return DB::transaction(function () use ($attributes, $propertyIds, $by): Loan {
            $loan = Loan::query()->create([
                ...$attributes,
                'reference' => $this->numbers->next(DocumentNumber::LOAN),
                'status' => LoanStatus::Pending,
                'created_by' => $by?->id,
            ]);
            $loan->properties()->sync($propertyIds);

            return $loan;
        });
    }

    /**
     * Change the terms of a loan that has not been drawn yet.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<int|string>  $propertyIds
     *
     * @throws LoanActionRefused
     */
    public function update(Loan $loan, array $attributes, array $propertyIds = []): Loan
    {
        if ($loan->status !== LoanStatus::Pending) {
            $loan->properties()->sync($propertyIds);
            $loan->update(array_intersect_key($attributes, array_flip(['name', 'lender_reference', 'notes'])));

            return $loan;
        }

        $attributes = $this->prepare($attributes);

        DB::transaction(function () use ($loan, $attributes, $propertyIds): void {
            $loan->update($attributes);
            $loan->properties()->sync($propertyIds);
        });

        return $loan->refresh();
    }

    /**
     * Receive money from the lender. Arrangement fees the lender deducts are charged to bank charges.
     *
     * @throws LoanActionRefused
     * @throws InvalidJournalEntry
     */
    public function drawdown(Loan $loan, string $amount, CarbonInterface|string $drawnOn, string $fees = '0', ?Account $bankAccount = null, ?string $reference = null): LoanDrawdown
    {
        $amount = Money::round($amount);
        $fees = Money::round($fees);
        $drawnOn = Carbon::parse($drawnOn);
        $bankAccount ??= $loan->bankAccount;

        if ($loan->status === LoanStatus::Closed) {
            throw new LoanActionRefused("{$loan->reference} has been repaid and closed.");
        }

        if (! Money::isPositive($amount)) {
            throw new LoanActionRefused('The drawdown must be greater than zero.');
        }

        if (bccomp($amount, $loan->undrawnAmount(), 2) > 0) {
            throw new LoanActionRefused('Only KES '.number_format((float) $loan->undrawnAmount(), 2)." of {$loan->reference} is left to draw.");
        }

        if (bccomp($fees, $amount, 2) >= 0) {
            throw new LoanActionRefused('Fees cannot be as much as the drawdown.');
        }

        if ($drawnOn->lt($loan->start_date) || $drawnOn->gte($loan->maturity_date)) {
            throw new LoanActionRefused('Drawdowns must fall between the loan start date and maturity.');
        }

        $drawdown = DB::transaction(function () use ($loan, $amount, $fees, $drawnOn, $bankAccount, $reference): LoanDrawdown {
            $drawdown = $loan->drawdowns()->create([
                'drawn_on' => $drawnOn,
                'amount' => $amount,
                'fees' => $fees,
                'bank_account_id' => $bankAccount->id,
                'reference' => $reference,
                'created_by' => Auth::id(),
            ]);

            $lines = [JournalLineData::debit($bankAccount, bcsub($amount, $fees, 2), $reference)];

            if (Money::isPositive($fees)) {
                $lines[] = JournalLineData::debit($this->accounts->get('bank_charges'), $fees, "Arrangement fees {$loan->reference}");
            }

            $lines[] = JournalLineData::credit($loan->liability_account_id, $amount, "{$loan->reference} {$loan->lender->name}");

            $entry = $this->posting->post($drawnOn, "Drawdown {$loan->reference} {$loan->name}", $lines, $reference ?? $loan->reference, $drawdown);
            $drawdown->update(['journal_entry_id' => $entry->id]);

            if ($loan->status === LoanStatus::Pending) {
                $loan->update(['status' => LoanStatus::Active]);
            }

            return $drawdown;
        });

        $this->rebuildSchedule($loan->refresh());

        return $drawdown;
    }

    /**
     * Pay the next instalment. The principal and interest default to the schedule but can be
     * set to the lender's figures; the rest of the schedule is recalculated from what was paid.
     *
     * @throws LoanActionRefused
     * @throws InvalidJournalEntry
     */
    public function payInstalment(LoanInstalment $instalment, CarbonInterface|string $paidOn, ?string $principal = null, ?string $interest = null, ?Account $bankAccount = null, ?string $reference = null): LoanRepayment
    {
        $loan = $instalment->loan;

        if ($instalment->isPaid()) {
            throw new LoanActionRefused("Instalment {$instalment->sequence} of {$loan->reference} is already paid.");
        }

        if ($loan->nextInstalment?->id !== $instalment->id) {
            throw new LoanActionRefused('Pay instalments in order: instalment '.$loan->nextInstalment?->sequence.' is due first.');
        }

        $principal = Money::round($principal ?? (string) $instalment->principal);
        $interest = Money::round($interest ?? (string) $instalment->interest);

        if (bccomp($principal, '0', 2) < 0 || bccomp($interest, '0', 2) < 0 || ! Money::isPositive(bcadd($principal, $interest, 2))) {
            throw new LoanActionRefused('Enter the principal and interest paid.');
        }

        if (bccomp($principal, $loan->outstandingPrincipal(), 2) > 0) {
            throw new LoanActionRefused('Only KES '.number_format((float) $loan->outstandingPrincipal(), 2).' of principal is outstanding.');
        }

        $repayment = DB::transaction(function () use ($loan, $instalment, $paidOn, $principal, $interest, $bankAccount, $reference): LoanRepayment {
            $repayment = $this->postRepayment($loan, $paidOn, $principal, $interest, $bankAccount, $reference, false, "Instalment {$instalment->sequence}");

            $instalment->update([
                'principal' => $principal,
                'interest' => $interest,
                'total' => bcadd($principal, $interest, 2),
                'closing_balance' => bcsub((string) $instalment->opening_balance, $principal, 2),
                'loan_repayment_id' => $repayment->id,
            ]);

            return $repayment;
        });

        $this->rebuildSchedule($loan->refresh());

        return $repayment;
    }

    /**
     * Repay principal early. The remaining instalments are recalculated over the same term, so they fall.
     *
     * @throws LoanActionRefused
     * @throws InvalidJournalEntry
     */
    public function prepay(Loan $loan, string $amount, CarbonInterface|string $paidOn, ?Account $bankAccount = null, ?string $reference = null): LoanRepayment
    {
        $amount = Money::round($amount);

        if ($loan->status !== LoanStatus::Active) {
            throw new LoanActionRefused("{$loan->reference} is not active.");
        }

        if (! Money::isPositive($amount) || bccomp($amount, $loan->outstandingPrincipal(), 2) > 0) {
            throw new LoanActionRefused('A prepayment must be more than zero and no more than the KES '.number_format((float) $loan->outstandingPrincipal(), 2).' outstanding.');
        }

        $repayment = DB::transaction(fn (): LoanRepayment => $this->postRepayment($loan, $paidOn, $amount, '0.00', $bankAccount, $reference, true, 'Prepayment'));

        $this->rebuildSchedule($loan->refresh());

        return $repayment;
    }

    /**
     * Record a new rate (e.g. after a base-rate move). It applies from the next unpaid instalment.
     *
     * @throws LoanActionRefused
     */
    public function changeRate(Loan $loan, CarbonInterface|string $effectiveFrom, ?string $rate = null, ?string $baseRate = null, ?string $margin = null, ?string $reason = null): void
    {
        if ($loan->status === LoanStatus::Closed) {
            throw new LoanActionRefused("{$loan->reference} has been repaid and closed.");
        }

        $isVariable = $loan->rate_type === InterestRateType::Variable;
        $baseRate = $isVariable ? ($baseRate ?? (string) $loan->base_rate) : null;
        $margin = $isVariable ? ($margin ?? (string) $loan->margin) : null;
        $newRate = $isVariable ? bcadd($baseRate, $margin, 3) : bcadd((string) $rate, '0', 3);

        if (bccomp($newRate, '0', 3) < 0 || bccomp($newRate, '100', 3) >= 0) {
            throw new LoanActionRefused('Enter a rate between 0% and 100%.');
        }

        DB::transaction(function () use ($loan, $effectiveFrom, $baseRate, $margin, $newRate, $reason): void {
            $loan->rateChanges()->create([
                'effective_from' => Carbon::parse($effectiveFrom),
                'base_rate' => $baseRate,
                'margin' => $margin,
                'previous_rate' => $loan->interest_rate,
                'interest_rate' => $newRate,
                'reason' => $reason,
                'created_by' => Auth::id(),
            ]);

            $loan->update(['interest_rate' => $newRate, 'base_rate' => $baseRate, 'margin' => $margin]);
        });

        $this->rebuildSchedule($loan->refresh());
    }

    /**
     * Rebuild the unpaid instalments from the outstanding principal, current rate and the
     * remaining due dates. Closes the loan once nothing is owed.
     */
    public function rebuildSchedule(Loan $loan): void
    {
        DB::transaction(function () use ($loan): void {
            $loan->instalments()->whereNull('loan_repayment_id')->delete();
            $paid = $loan->instalments()->get();
            $balance = $loan->outstandingPrincipal();

            if (! Money::isPositive($balance)) {
                if ($loan->status === LoanStatus::Active) {
                    $loan->update(['status' => LoanStatus::Closed, 'closed_at' => now()]);
                }

                return;
            }

            $dates = array_slice($this->dueDates($loan), $paid->count()) ?: [$loan->maturity_date->copy()];
            $rows = $this->schedule->build(
                $balance,
                (string) $loan->interest_rate,
                $loan->payment_frequency,
                $dates,
                $paid->last()?->due_date ?? $loan->drawdowns()->first()?->drawn_on ?? $loan->start_date,
                $loan->repayment_type,
                max(0, $loan->interest_only_periods - $paid->count()),
                (string) $loan->balloon_amount,
            );

            foreach ($rows as $index => $row) {
                $loan->instalments()->create([...$row, 'sequence' => $paid->count() + $index + 1, 'interest_rate' => $loan->interest_rate]);
            }
        });
    }

    /**
     * Every instalment date from the first payment to maturity, maturity always last.
     *
     * @return list<Carbon>
     */
    public function dueDates(Loan $loan): array
    {
        $dates = [];
        $months = $loan->payment_frequency->months();

        for ($i = 0; ($date = $loan->first_payment_date->copy()->addMonthsNoOverflow($i * $months))->lt($loan->maturity_date); $i++) {
            $dates[] = $date;
        }

        $dates[] = $loan->maturity_date->copy();

        return $dates;
    }

    /**
     * What the schedule would look like if the whole facility were drawn on the start date.
     *
     * @return list<array{due_date: CarbonInterface, opening_balance: string, principal: string, interest: string, total: string, closing_balance: string}>
     */
    public function projectedSchedule(Loan $loan): array
    {
        return $this->schedule->build(
            (string) $loan->facility_amount,
            (string) $loan->interest_rate,
            $loan->payment_frequency,
            $this->dueDates($loan),
            $loan->start_date,
            $loan->repayment_type,
            $loan->interest_only_periods,
            (string) $loan->balloon_amount,
        );
    }

    private function postRepayment(Loan $loan, CarbonInterface|string $paidOn, string $principal, string $interest, ?Account $bankAccount, ?string $reference, bool $isPrepayment, string $label): LoanRepayment
    {
        $bankAccount ??= $loan->bankAccount;
        $withholding = Money::percentOf($interest, (string) $loan->lender->interest_withholding_rate);
        $cash = bcsub(bcadd($principal, $interest, 2), $withholding, 2);

        $repayment = $loan->repayments()->create([
            'paid_on' => Carbon::parse($paidOn),
            'is_prepayment' => $isPrepayment,
            'principal' => $principal,
            'interest' => $interest,
            'withholding_tax' => $withholding,
            'amount_paid' => $cash,
            'bank_account_id' => $bankAccount->id,
            'reference' => $reference,
            'created_by' => Auth::id(),
        ]);

        $lines = [];

        if (Money::isPositive($principal)) {
            $lines[] = JournalLineData::debit($loan->liability_account_id, $principal, "{$loan->reference} principal");
        }

        if (Money::isPositive($interest)) {
            $lines[] = JournalLineData::debit($this->accounts->get('interest_expense'), $interest, "{$loan->reference} interest");
        }

        $lines[] = JournalLineData::credit($bankAccount, $cash, $reference);

        if (Money::isPositive($withholding)) {
            $lines[] = JournalLineData::credit($this->accounts->get('withholding_tax_payable'), $withholding, "WHT on interest to {$loan->lender->name}");
        }

        $entry = $this->posting->post($paidOn, "{$label} {$loan->reference} {$loan->lender->name}", $lines, $reference ?? $loan->reference, $repayment);
        $repayment->update(['journal_entry_id' => $entry->id]);

        return $repayment;
    }

    /**
     * Validate terms and fill in the derived fields.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function prepare(array $attributes): array
    {
        $type = $attributes['type'] instanceof LoanType ? $attributes['type'] : LoanType::from($attributes['type']);
        $rateType = $attributes['rate_type'] instanceof InterestRateType ? $attributes['rate_type'] : InterestRateType::from($attributes['rate_type']);
        $repaymentType = $attributes['repayment_type'] instanceof RepaymentType ? $attributes['repayment_type'] : RepaymentType::from($attributes['repayment_type']);
        $start = Carbon::parse($attributes['start_date']);
        $firstPayment = Carbon::parse($attributes['first_payment_date']);
        $maturity = Carbon::parse($attributes['maturity_date']);
        $facility = Money::round((string) $attributes['facility_amount']);
        $balloon = Money::round((string) ($attributes['balloon_amount'] ?? '0'));

        if (! Money::isPositive($facility)) {
            throw new LoanActionRefused('The facility amount must be greater than zero.');
        }

        if ($firstPayment->lte($start) || $maturity->lt($firstPayment)) {
            throw new LoanActionRefused('The first payment must be after the start date, and maturity on or after the first payment.');
        }

        if (bccomp($balloon, $facility, 2) >= 0) {
            throw new LoanActionRefused('The balloon must be less than the facility amount.');
        }

        $rate = $rateType === InterestRateType::Variable
            ? bcadd((string) ($attributes['base_rate'] ?? '0'), (string) ($attributes['margin'] ?? '0'), 3)
            : bcadd((string) ($attributes['interest_rate'] ?? '0'), '0', 3);

        if (bccomp($rate, '0', 3) < 0 || bccomp($rate, '100', 3) >= 0) {
            throw new LoanActionRefused('Enter an interest rate between 0% and 100%.');
        }

        return [
            'lender_id' => (int) $attributes['lender_id'],
            'name' => $attributes['name'],
            'lender_reference' => $attributes['lender_reference'] ?? null,
            'type' => $type,
            'facility_amount' => $facility,
            'start_date' => $start,
            'first_payment_date' => $firstPayment,
            'maturity_date' => $maturity,
            'payment_frequency' => $attributes['payment_frequency'],
            'repayment_type' => $repaymentType,
            'interest_only_periods' => $repaymentType === RepaymentType::InterestOnly ? 0 : (int) ($attributes['interest_only_periods'] ?? 0),
            'balloon_amount' => $repaymentType === RepaymentType::InterestOnly ? '0.00' : $balloon,
            'rate_type' => $rateType,
            'base_rate_name' => $rateType === InterestRateType::Variable ? ($attributes['base_rate_name'] ?? null) : null,
            'base_rate' => $rateType === InterestRateType::Variable ? $attributes['base_rate'] : null,
            'margin' => $rateType === InterestRateType::Variable ? $attributes['margin'] : null,
            'interest_rate' => $rate,
            'liability_account_id' => $attributes['liability_account_id'] ?? $this->accounts->get($type->liabilityAccountKey())->id,
            'bank_account_id' => $attributes['bank_account_id'] ?? $this->accounts->get('disbursement.bank_transfer')->id,
            'notes' => $attributes['notes'] ?? null,
        ];
    }
}
