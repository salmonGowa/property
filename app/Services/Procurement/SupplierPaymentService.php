<?php

namespace App\Services\Procurement;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Models\Vendor;
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
 * Pays suppliers: debit Accounts Payable, credit the bank, M-Pesa or cash account,
 * and apply the payment to the chosen bills, earliest due first.
 */
class SupplierPaymentService
{
    public function __construct(
        private PostingService $posting,
        private LedgerAccounts $accounts,
        private DocumentNumber $numbers,
        private SupplierBillService $bills,
    ) {}

    /**
     * @param  iterable<SupplierBill>  $bills  The bills this payment settles
     *
     * @throws InvalidJournalEntry
     * @throws ProcurementActionRefused
     */
    public function pay(
        Vendor $vendor,
        CarbonInterface|string $paidOn,
        PaymentMethod $method,
        string $amount,
        iterable $bills,
        ?string $reference = null,
        ?string $notes = null,
    ): SupplierPayment {
        $amount = Money::round($amount);
        $reference = filled($reference) ? strtoupper(trim($reference)) : null;
        $bills = collect($bills)->sortBy([['due_date', 'asc'], ['id', 'asc']])->values();

        if (! in_array($method, PaymentMethod::disbursable(), true)) {
            throw new ProcurementActionRefused("Suppliers cannot be paid by {$method->getLabel()}.");
        }

        if (! Money::isPositive($amount)) {
            throw new ProcurementActionRefused('The amount paid must be greater than zero.');
        }

        if ($reference === null && in_array($method, [PaymentMethod::Mpesa, PaymentMethod::Cheque], true)) {
            throw new ProcurementActionRefused("Enter the {$method->getLabel()} reference.");
        }

        if ($bills->isEmpty()) {
            throw new ProcurementActionRefused('Choose the bills this payment settles.');
        }

        foreach ($bills as $bill) {
            if ($bill->vendor_id !== $vendor->id || ! in_array($bill->status, [InvoiceStatus::Unpaid, InvoiceStatus::PartiallyPaid], true)) {
                throw new ProcurementActionRefused("{$bill->number} is not an unpaid bill from {$vendor->name}.");
            }
        }

        $outstanding = Money::sum($bills->map(fn (SupplierBill $bill): string => $bill->balanceDue()));

        if (bccomp($amount, $outstanding, 2) > 0) {
            throw new ProcurementActionRefused("The chosen bills only total KES {$outstanding}. Supplier advances are not supported yet.");
        }

        $paidFrom = $this->accounts->disbursementAccountFor($method);

        $payment = DB::transaction(function () use ($vendor, $paidOn, $method, $amount, $reference, $notes, $paidFrom, $bills): SupplierPayment {
            $payment = SupplierPayment::query()->create([
                'number' => $this->numbers->next(DocumentNumber::SUPPLIER_PAYMENT),
                'vendor_id' => $vendor->id,
                'paid_on' => Carbon::parse($paidOn),
                'method' => $method,
                'reference' => $reference,
                'amount' => $amount,
                'paid_from_account_id' => $paidFrom->id,
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);

            $entry = $this->posting->post($paidOn, "{$payment->number} paid {$vendor->name} via {$method->getLabel()}", [
                JournalLineData::debit($this->accounts->get('payable'), $amount, $vendor->name),
                JournalLineData::credit($paidFrom, $amount, $reference),
            ], $reference ?? $payment->number, $payment);

            $payment->update(['journal_entry_id' => $entry->id]);

            $remaining = $amount;

            foreach ($bills as $bill) {
                if (! Money::isPositive($remaining)) {
                    break;
                }

                $applied = Money::min($remaining, $bill->balanceDue());
                $payment->allocations()->create(['supplier_bill_id' => $bill->id, 'amount' => $applied]);
                $this->bills->refreshStatus($bill);
                $remaining = bcsub($remaining, $applied, 2);
            }

            return $payment;
        });

        return $payment->refresh();
    }

    /**
     * Cancel a payment (e.g. a stopped cheque): reverse its posting and reopen the bills it settled.
     *
     * @throws InvalidJournalEntry
     */
    public function reverse(SupplierPayment $payment, string $reason, User $user, CarbonInterface|string|null $date = null): void
    {
        if ($payment->status === PaymentStatus::Reversed) {
            throw new InvalidJournalEntry("{$payment->number} has already been reversed.");
        }

        DB::transaction(function () use ($payment, $reason, $user, $date): void {
            $reversal = $this->posting->reverse($payment->journalEntry, $date ?? today(), $reason);
            $bills = SupplierBill::query()->whereIn('id', $payment->allocations()->select('supplier_bill_id'))->get();

            $payment->allocations()->delete();
            $bills->each(fn (SupplierBill $bill) => $this->bills->refreshStatus($bill));

            $payment->update([
                'status' => PaymentStatus::Reversed,
                'reversal_journal_entry_id' => $reversal->id,
                'reversed_at' => now(),
                'reversed_by' => $user->id,
                'reversal_reason' => $reason,
            ]);
        });
    }
}
