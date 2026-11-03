<?php

namespace App\Filament\Resources\SupplierBills\Pages;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\SupplierBills\SupplierBillResource;
use App\Filament\Resources\SupplierPayments\SupplierPaymentResource;
use App\Models\SupplierBill;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;

class ViewSupplierBill extends ViewRecord
{
    protected static string $resource = SupplierBillResource::class;

    public function getTitle(): string
    {
        return "Bill {$this->getRecord()->number}";
    }

    protected function getHeaderActions(): array
    {
        /** @var SupplierBill $bill */
        $bill = $this->getRecord();

        return [
            SupplierPaymentResource::payAction($bill->vendor, $bill)
                ->visible(in_array($bill->status, [InvoiceStatus::Unpaid, InvoiceStatus::PartiallyPaid], true))
                ->after(fn () => $this->refreshFormData(['status', 'amount_paid'])),
            ActionGroup::make([
                SupplierBillResource::voidAction()->after(fn () => $this->refreshFormData(['status', 'voided_at', 'void_reason'])),
            ]),
        ];
    }
}
