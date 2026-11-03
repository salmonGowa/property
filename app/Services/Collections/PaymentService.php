<?php

namespace App\Services\Collections;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Tenant;
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
 * Receipts money from tenants: debit the bank/M-Pesa/cash account, credit Accounts
 * Receivable, then apply the money to the tenant's oldest outstanding invoices.
 */
class PaymentService
{
    public function __construct(
        private PostingService $posting,
        private LedgerAccounts $accounts,
        private DocumentNumber $numbers,
        private AllocationService $allocations,
    ) {}

    /**
     * @throws InvalidJournalEntry
     */
    public function record(
        Tenant $tenant,
        CarbonInterface|string $receivedOn,
        PaymentMethod $method,
        string $amount,
        ?string $reference = null,
        ?string $notes = null,
        ?Lease $lease = null,
    ): Payment {
        $amount = Money::round($amount);
        $reference = filled($reference) ? strtoupper(trim($reference)) : null;

        if (! Money::isPositive($amount)) {
            throw new InvalidJournalEntry('The amount received must be greater than zero.');
        }

        if ($method === PaymentMethod::Mpesa && $reference === null) {
            throw new InvalidJournalEntry('Enter the M-Pesa transaction code.');
        }

        if ($reference !== null && $this->referenceAlreadyUsed($method, $reference)) {
            throw new InvalidJournalEntry("A {$method->getLabel()} payment with reference {$reference} has already been recorded.");
        }

        $depositAccount = $this->accounts->depositAccountFor($method);

        $payment = DB::transaction(function () use ($tenant, $receivedOn, $method, $amount, $reference, $notes, $lease, $depositAccount): Payment {
            $payment = Payment::query()->create([
                'number' => $this->numbers->next(DocumentNumber::PAYMENT),
                'tenant_id' => $tenant->id,
                'lease_id' => $lease?->id,
                'received_on' => Carbon::parse($receivedOn),
                'method' => $method,
                'reference' => $reference,
                'amount' => $amount,
                'deposit_account_id' => $depositAccount->id,
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);

            $entry = $this->posting->post($receivedOn, "{$payment->number} {$tenant->name} via {$method->getLabel()}", [
                JournalLineData::debit($depositAccount, $amount, $reference),
                JournalLineData::credit($this->accounts->get('receivable'), $amount, $tenant->name),
            ], $reference ?? $payment->number, $payment);

            $payment->update(['journal_entry_id' => $entry->id]);

            return $payment;
        });

        $this->allocations->allocate($payment);

        return $payment->refresh();
    }

    /**
     * Cancel a payment (e.g. a bounced cheque): reverse its posting and un-apply it from invoices.
     *
     * @throws InvalidJournalEntry
     */
    public function reverse(Payment $payment, string $reason, User $user, CarbonInterface|string|null $date = null): void
    {
        if ($payment->status === PaymentStatus::Reversed) {
            throw new InvalidJournalEntry("{$payment->number} has already been reversed.");
        }

        DB::transaction(function () use ($payment, $reason, $user, $date): void {
            $reversal = $this->posting->reverse($payment->journalEntry, $date ?? today(), $reason);
            $invoices = Invoice::query()->whereIn('id', $payment->allocations()->select('invoice_id'))->get();

            $payment->allocations()->delete();
            $invoices->each(fn (Invoice $invoice) => $this->allocations->refreshInvoiceStatus($invoice));

            $payment->update([
                'status' => PaymentStatus::Reversed,
                'reversal_journal_entry_id' => $reversal->id,
                'reversed_at' => now(),
                'reversed_by' => $user->id,
                'reversal_reason' => $reason,
            ]);
        });

        $this->allocations->applyCredits($payment->tenant);
    }

    private function referenceAlreadyUsed(PaymentMethod $method, string $reference): bool
    {
        return Payment::query()
            ->where('method', $method)
            ->where('reference', $reference)
            ->where('status', PaymentStatus::Posted)
            ->exists();
    }
}
