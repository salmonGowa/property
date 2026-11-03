<?php

namespace App\Services\Collections;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * A tenant's account history: invoices (debits), payments and credit notes (credits) with a running balance.
 */
class TenantStatement
{
    /**
     * @return array{opening: string, closing: string, rows: Collection<int, array{date: CarbonInterface, document: string, description: string, debit: string, credit: string, balance: string}>}
     */
    public function build(Tenant $tenant, CarbonInterface $from, CarbonInterface $to): array
    {
        $invoices = $tenant->invoices()->where('status', '!=', InvoiceStatus::Void);
        $payments = $tenant->payments()->where('status', PaymentStatus::Posted);

        $creditNotes = $tenant->creditNotes();

        $opening = bcsub(bcsub(
            (string) (clone $invoices)->whereDate('issue_date', '<', $from)->sum('total'),
            (string) (clone $payments)->whereDate('received_on', '<', $from)->sum('amount'),
            2,
        ), (string) (clone $creditNotes)->whereDate('issue_date', '<', $from)->sum('amount'), 2);

        $entries = (clone $invoices)->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])->get()
            ->map(fn (Invoice $invoice): array => [
                'date' => $invoice->issue_date,
                'sort' => $invoice->issue_date->toDateString().'-0-'.$invoice->id,
                'document' => $invoice->number,
                'description' => $invoice->description,
                'debit' => (string) $invoice->total,
                'credit' => '0.00',
            ])
            ->concat((clone $payments)->whereBetween('received_on', [$from->toDateString(), $to->toDateString()])->get()
                ->map(fn (Payment $payment): array => [
                    'date' => $payment->received_on,
                    'sort' => $payment->received_on->toDateString().'-1-'.$payment->id,
                    'document' => $payment->number,
                    'description' => trim("Payment - {$payment->method->getLabel()} {$payment->reference}"),
                    'debit' => '0.00',
                    'credit' => (string) $payment->amount,
                ]))
            ->concat((clone $creditNotes)->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])->with('invoice')->get()
                ->map(fn (CreditNote $creditNote): array => [
                    'date' => $creditNote->issue_date,
                    'sort' => $creditNote->issue_date->toDateString().'-2-'.$creditNote->id,
                    'document' => $creditNote->number,
                    'description' => "{$creditNote->type->getLabel()} on {$creditNote->invoice->number}: {$creditNote->reason}",
                    'debit' => '0.00',
                    'credit' => (string) $creditNote->amount,
                ]))
            ->sortBy('sort')
            ->values();

        $balance = $opening;
        $rows = $entries->map(function (array $entry) use (&$balance): array {
            $balance = bcsub(bcadd($balance, $entry['debit'], 2), $entry['credit'], 2);
            unset($entry['sort']);

            return [...$entry, 'balance' => $balance];
        });

        return ['opening' => $opening, 'closing' => $balance, 'rows' => $rows];
    }
}
