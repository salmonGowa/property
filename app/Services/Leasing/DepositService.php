<?php

namespace App\Services\Leasing;

use App\Enums\ChargeType;
use App\Enums\CreditNoteType;
use App\Enums\LeaseStatus;
use App\Enums\PaymentMethod;
use App\Models\DepositRefund;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\PaymentAllocation;
use App\Services\Billing\CreditNoteService;
use App\Services\Billing\InvoiceService;
use App\Services\Collections\PaymentService;
use App\Services\Ledger\InvalidJournalEntry;
use App\Services\Ledger\JournalLineData;
use App\Services\Ledger\LedgerAccounts;
use App\Services\Ledger\PostingService;
use App\Services\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Security deposits: what is held for a lease, and settling it when the tenant moves out
 * (deduct damages and unpaid amounts, refund the rest).
 */
class DepositService
{
    public function __construct(
        private InvoiceService $invoices,
        private CreditNoteService $creditNotes,
        private PaymentService $payments,
        private PostingService $posting,
        private LedgerAccounts $accounts,
    ) {}

    /**
     * Deposit actually paid in and not yet used or refunded. A renewal carries its
     * predecessor's deposit, so the whole renewal chain counts.
     */
    public function heldFor(Lease $lease): string
    {
        $leaseIds = $this->chain($lease);

        $paidIn = PaymentAllocation::query()
            ->whereNotNull('payment_id')
            ->whereIn('invoice_id', $this->depositInvoices($leaseIds)->pluck('id'))
            ->sum('amount');

        $usedAndRefunded = bcadd(
            (string) Lease::query()->whereKey($leaseIds)->get()->sum(fn (Lease $chainLease): float => (float) $chainLease->depositOffsets()->sum('amount')),
            (string) DepositRefund::query()->whereIn('lease_id', $leaseIds)->sum('amount'),
            2,
        );

        return bcsub((string) $paidIn, $usedAndRefunded, 2);
    }

    /**
     * Settle the deposit of a terminated lease.
     *
     * @param  list<array{description: string, amount: string}>  $deductions  Damage or cleaning charges to bill first
     *
     * @throws LeaseActionRefused
     * @throws InvalidJournalEntry
     */
    public function settle(Lease $lease, CarbonInterface|string $date, array $deductions = [], ?PaymentMethod $refundMethod = null, ?string $refundReference = null): DepositSettlement
    {
        if ($lease->status !== LeaseStatus::Terminated) {
            throw new LeaseActionRefused('Terminate the lease before settling its deposit.');
        }

        $date = Carbon::parse($date);
        $tenant = $lease->tenant;

        return DB::transaction(function () use ($lease, $date, $deductions, $refundMethod, $refundReference, $tenant): DepositSettlement {
            foreach ($this->depositInvoices($this->chain($lease))->get() as $depositInvoice) {
                if (Money::isPositive($depositInvoice->balanceDue())) {
                    $this->creditNotes->issue($depositInvoice, CreditNoteType::Adjustment, $depositInvoice->balanceDue(), 'Unpaid deposit cancelled at move-out', $date);
                }
            }

            $deductions = array_values(array_filter($deductions, fn (array $line): bool => Money::isPositive(Money::round((string) $line['amount']))));

            if ($deductions !== []) {
                $this->invoices->issue($tenant, $date, $date, "{$lease->unit->fullCode()} - Move-out charges", array_map(fn (array $line): array => [
                    'charge_type' => ChargeType::Other,
                    'description' => $line['description'],
                    'amount' => (string) $line['amount'],
                    'vat' => false,
                ], $deductions), $lease);
            }

            $held = $this->heldFor($lease);
            $owed = $tenant->refresh()->balance();
            $offset = Money::isPositive($owed) ? Money::min($held, $owed) : '0.00';

            if (Money::isPositive($offset)) {
                $this->payments->record($tenant, $date, PaymentMethod::DepositOffset, $offset, null, "Deposit applied at move-out, lease {$lease->reference}", $lease);
            }

            $refundable = bcsub($held, $offset, 2);
            $refund = null;

            if (Money::isPositive($refundable) && $refundMethod !== null) {
                $refund = $this->refund($lease, $date, $refundable, $refundMethod, $refundReference);
            }

            return new DepositSettlement($held, $offset, $refund?->amount ?? '0.00', $refund === null ? $refundable : '0.00');
        });
    }

    /**
     * @throws InvalidJournalEntry
     */
    private function refund(Lease $lease, CarbonInterface $date, string $amount, PaymentMethod $method, ?string $reference): DepositRefund
    {
        $paidFrom = $this->accounts->depositAccountFor($method);

        $refund = DepositRefund::query()->create([
            'lease_id' => $lease->id,
            'tenant_id' => $lease->tenant_id,
            'paid_on' => $date,
            'method' => $method,
            'reference' => $reference,
            'amount' => $amount,
            'paid_from_account_id' => $paidFrom->id,
            'created_by' => Auth::id(),
        ]);

        $entry = $this->posting->post($date, "Deposit refund to {$lease->tenant->name}, lease {$lease->reference}", [
            JournalLineData::debit($this->accounts->get('tenant_deposits'), $amount, $lease->tenant->name),
            JournalLineData::credit($paidFrom, $amount, $reference),
        ], $reference ?? $lease->reference, $refund);

        $refund->update(['journal_entry_id' => $entry->id]);

        return $refund;
    }

    /**
     * @return list<int>
     */
    private function chain(Lease $lease): array
    {
        $ids = [];
        $current = $lease;

        while ($current !== null && ! in_array($current->id, $ids, true)) {
            $ids[] = $current->id;
            $current = $current->renewedFrom;
        }

        return $ids;
    }

    /**
     * @param  list<int>  $leaseIds
     */
    private function depositInvoices(array $leaseIds): Builder
    {
        return Invoice::query()->whereIn('billing_key', array_map(fn (int $id): string => "lease:{$id}:deposit", $leaseIds));
    }
}
