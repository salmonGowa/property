<?php

namespace App\Services\Collections;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\Money;
use Illuminate\Support\Facades\DB;

/**
 * Applies money and credits to invoices. Payments and credit notes both settle
 * invoices through allocations, so an invoice's amount_paid is everything applied to it.
 */
class AllocationService
{
    /**
     * Apply a payment's or credit note's unapplied balance, to a preferred invoice first,
     * then to the tenant's outstanding invoices, oldest due first.
     */
    public function allocate(Payment|CreditNote $source, ?Invoice $preferred = null): void
    {
        DB::transaction(function () use ($source, $preferred): void {
            $remaining = $source->unallocatedAmount();

            if (! Money::isPositive($remaining)) {
                return;
            }

            $invoices = Invoice::query()
                ->whereBelongsTo($source->tenant)
                ->outstanding()
                ->when($preferred, fn ($query) => $query->orderByRaw('id = ? desc', [$preferred->id]))
                ->orderBy('due_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($invoices as $invoice) {
                if (! Money::isPositive($remaining)) {
                    break;
                }

                $applied = Money::min($remaining, $invoice->balanceDue());

                $allocation = $source->allocations()->firstOrNew(['invoice_id' => $invoice->id]);
                $allocation->amount = bcadd((string) ($allocation->amount ?? '0'), $applied, 2);
                $allocation->save();

                $this->refreshInvoiceStatus($invoice);
                $remaining = bcsub($remaining, $applied, 2);
            }
        });
    }

    /**
     * Apply every unapplied payment and credit note the tenant has to their outstanding invoices.
     */
    public function applyCredits(Tenant $tenant): void
    {
        $tenant->creditNotes()->orderBy('issue_date')->orderBy('id')->get()
            ->each(fn (CreditNote $creditNote) => $this->allocate($creditNote));

        $tenant->payments()->where('status', PaymentStatus::Posted)->orderBy('received_on')->orderBy('id')->get()
            ->each(fn (Payment $payment) => $this->allocate($payment));
    }

    public function refreshInvoiceStatus(Invoice $invoice): void
    {
        if ($invoice->status === InvoiceStatus::Void) {
            return;
        }

        $settled = Money::round((string) $invoice->allocations()->sum('amount'));

        $invoice->update([
            'amount_paid' => $settled,
            'status' => match (true) {
                bccomp($settled, (string) $invoice->total, 2) >= 0 => InvoiceStatus::Paid,
                Money::isPositive($settled) => InvoiceStatus::PartiallyPaid,
                default => InvoiceStatus::Unpaid,
            },
        ]);
    }
}
