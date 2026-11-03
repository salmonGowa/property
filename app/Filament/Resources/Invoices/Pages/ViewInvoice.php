<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Enums\CreditNoteType;
use App\Enums\InvoiceStatus;
use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    public function getTitle(): string
    {
        return "Invoice {$this->getRecord()->number}";
    }

    protected function getHeaderActions(): array
    {
        /** @var Invoice $invoice */
        $invoice = $this->getRecord();

        return [
            PaymentResource::recordPaymentAction($invoice->tenant, $invoice)
                ->visible(in_array($invoice->status, [InvoiceStatus::Unpaid, InvoiceStatus::PartiallyPaid], true))
                ->after(fn () => $this->refreshFormData(['status', 'amount_paid'])),
            Action::make('print')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(fn (Invoice $record): string => route('filament.admin.invoices.print', $record))
                ->openUrlInNewTab(),
            CreditNoteResource::issueAction($invoice, 'waivePenalty', CreditNoteType::PenaltyWaiver)
                ->after(fn () => $this->refreshFormData(['status', 'amount_paid'])),
            ActionGroup::make([
                CreditNoteResource::issueAction($invoice)->after(fn () => $this->refreshFormData(['status', 'amount_paid'])),
                CreditNoteResource::issueAction($invoice, 'writeOff', CreditNoteType::WriteOff)->after(fn () => $this->refreshFormData(['status', 'amount_paid'])),
                InvoiceResource::voidAction()->after(fn () => $this->refreshFormData(['status', 'voided_at', 'void_reason'])),
            ]),
        ];
    }
}
