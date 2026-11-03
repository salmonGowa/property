<?php

namespace App\Filament\Resources\Vendors\Pages;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\SupplierBills\SupplierBillResource;
use App\Filament\Resources\SupplierPayments\SupplierPaymentResource;
use App\Filament\Resources\Vendors\VendorResource;
use App\Models\PurchaseOrder;
use App\Models\SupplierBill;
use App\Models\Vendor;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

class ViewVendor extends ViewRecord
{
    protected static string $resource = VendorResource::class;

    protected function getHeaderActions(): array
    {
        /** @var Vendor $vendor */
        $vendor = $this->getRecord();

        return [
            SupplierPaymentResource::payAction($vendor)
                ->visible(fn (): bool => $vendor->bills()->outstanding()->exists()),
            Action::make('recordBill')
                ->label('Record bill')
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('gray')
                ->authorize(fn (): bool => Gate::allows('create', SupplierBill::class))
                ->url(SupplierBillResource::getUrl('create', ['vendor' => $vendor->id])),
            Action::make('newPurchaseOrder')
                ->label('New purchase order')
                ->icon(Heroicon::OutlinedShoppingCart)
                ->color('gray')
                ->authorize(fn (): bool => Gate::allows('create', PurchaseOrder::class))
                ->url(PurchaseOrderResource::getUrl('create', ['vendor' => $vendor->id])),
            EditAction::make(),
        ];
    }
}
