<?php

namespace App\Services\Billing;

use App\Enums\ChargeType;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Collections\AllocationService;
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
 * Issues and voids tenant invoices. Every invoice is posted to the ledger:
 * debit Accounts Receivable, credit income (and VAT output) per line.
 */
class InvoiceService
{
    public function __construct(
        private PostingService $posting,
        private LedgerAccounts $accounts,
        private DocumentNumber $numbers,
        private AllocationService $allocations,
    ) {}

    /**
     * @param  list<array{charge_type: ChargeType, description: string, amount: string, vat: bool}>  $lines
     *
     * @throws InvalidJournalEntry
     */
    public function issue(
        Tenant $tenant,
        CarbonInterface|string $issueDate,
        CarbonInterface|string $dueDate,
        string $description,
        array $lines,
        ?Lease $lease = null,
        ?CarbonInterface $periodStart = null,
        ?CarbonInterface $periodEnd = null,
        ?string $billingKey = null,
    ): Invoice {
        $issueDate = Carbon::parse($issueDate);
        $dueDate = Carbon::parse($dueDate);
        $lines = array_values(array_filter($lines, fn (array $line): bool => Money::isPositive($line['amount'])));

        if ($lines === []) {
            throw new InvalidJournalEntry('An invoice needs at least one charge greater than zero.');
        }

        if ($dueDate->lt($issueDate)) {
            throw new InvalidJournalEntry('The due date cannot be before the issue date.');
        }

        $vatRate = (string) config('ledger.vat_rate');
        $prepared = array_map(fn (array $line): array => [
            'charge_type' => $line['charge_type'],
            'description' => $line['description'],
            'amount' => Money::round($line['amount']),
            'vat_rate' => $line['vat'] ? $vatRate : '0.00',
            'vat_amount' => $line['vat'] ? Money::percentOf($line['amount'], $vatRate) : '0.00',
            'account_id' => $this->incomeAccountFor($line['charge_type'], $lease)->id,
        ], $lines);

        $subtotal = Money::sum(array_column($prepared, 'amount'));
        $vat = Money::sum(array_column($prepared, 'vat_amount'));
        $total = bcadd($subtotal, $vat, 2);

        $invoice = DB::transaction(function () use ($tenant, $issueDate, $dueDate, $description, $prepared, $lease, $periodStart, $periodEnd, $billingKey, $subtotal, $vat, $total): Invoice {
            $invoice = Invoice::query()->create([
                'number' => $this->numbers->next(DocumentNumber::INVOICE),
                'tenant_id' => $tenant->id,
                'lease_id' => $lease?->id,
                'billing_key' => $billingKey,
                'description' => $description,
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'subtotal' => $subtotal,
                'vat_amount' => $vat,
                'total' => $total,
                'created_by' => Auth::id(),
            ]);
            $invoice->lines()->createMany($prepared);

            $journalLines = [JournalLineData::debit($this->accounts->get('receivable'), $total, $tenant->name)];

            foreach ($prepared as $line) {
                $journalLines[] = JournalLineData::credit($line['account_id'], $line['amount'], $line['description']);
            }

            if (Money::isPositive($vat)) {
                $journalLines[] = JournalLineData::credit($this->accounts->get('vat_output'), $vat, 'VAT output');
            }

            $entry = $this->posting->post($issueDate, "{$invoice->number} {$tenant->name}: {$description}", $journalLines, $invoice->number, $invoice);
            $invoice->update(['journal_entry_id' => $entry->id]);

            return $invoice;
        });

        $this->allocations->applyCredits($tenant);

        return $invoice->refresh();
    }

    /**
     * Cancel an invoice by reversing its ledger posting. Paid invoices must have their payments reversed first.
     *
     * @throws InvalidJournalEntry
     */
    public function void(Invoice $invoice, string $reason, ?User $user, CarbonInterface|string|null $date = null): void
    {
        if ($invoice->status === InvoiceStatus::Void) {
            throw new InvalidJournalEntry("{$invoice->number} is already void.");
        }

        if ($invoice->allocations()->exists()) {
            throw new InvalidJournalEntry("{$invoice->number} has payments or credit notes applied. Reverse those before voiding it.");
        }

        DB::transaction(function () use ($invoice, $reason, $user, $date): void {
            $reversal = $this->posting->reverse($invoice->journalEntry, $date ?? today(), "Void {$invoice->number}: {$reason}");

            $invoice->update([
                'status' => InvoiceStatus::Void,
                'billing_key' => null,
                'void_journal_entry_id' => $reversal->id,
                'voided_at' => now(),
                'voided_by' => $user?->id,
                'void_reason' => $reason,
            ]);
        });
    }

    private function incomeAccountFor(ChargeType $chargeType, ?Lease $lease): Account
    {
        return match ($chargeType) {
            ChargeType::Rent => $this->accounts->get($lease?->unit->type->isCommercial() ? 'rent_commercial' : 'rent_residential'),
            ChargeType::ServiceCharge => $this->accounts->get('service_charge'),
            ChargeType::Deposit => $this->accounts->get('tenant_deposits'),
            ChargeType::Utility => $this->accounts->get('utility_recharge'),
            ChargeType::Penalty => $this->accounts->get('penalties'),
            ChargeType::Other => $this->accounts->get('other_income'),
        };
    }
}
