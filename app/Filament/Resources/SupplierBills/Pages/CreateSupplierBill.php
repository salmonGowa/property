<?php

namespace App\Filament\Resources\SupplierBills\Pages;

use App\Filament\Resources\SupplierBills\SupplierBillResource;
use App\Models\Property;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\Ledger\InvalidJournalEntry;
use App\Services\Procurement\ProcurementActionRefused;
use App\Services\Procurement\SupplierBillService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateSupplierBill extends CreateRecord
{
    protected static string $resource = SupplierBillResource::class;

    protected static bool $canCreateAnother = false;

    protected static ?string $title = 'Record supplier bill';

    protected function afterFill(): void
    {
        $state = $this->form->getRawState();
        $order = PurchaseOrder::query()->find(request()->query('purchase_order'));
        $workOrder = WorkOrder::query()->find(request()->query('work_order'));
        $vendor = Vendor::query()->find(request()->query('vendor') ?? $order?->vendor_id ?? $workOrder?->vendor_id);

        if ($vendor) {
            $state['vendor_id'] = $vendor->id;
            $state['due_date'] = today()->addDays($vendor->payment_terms_days)->toDateString();
        }

        if ($workOrder) {
            $state['work_order_id'] = $workOrder->id;
            $state['property_id'] = $workOrder->property_id;
            $state['description'] = "{$workOrder->number}: {$workOrder->title}";
        }

        if ($order) {
            $state = [...$state, 'purchase_order_id' => $order->id, ...SupplierBillResource::orderState($order)];
        }

        $this->form->fill($state);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(SupplierBillService::class)->record(
                Vendor::query()->findOrFail($data['vendor_id']),
                $data['vendor_invoice_number'],
                $data['bill_date'],
                $data['due_date'],
                $data['description'],
                array_map(fn (array $line): array => [
                    'account_id' => $line['account_id'],
                    'description' => $line['description'],
                    'amount' => (string) $line['amount'],
                    'vat' => (bool) ($line['vat'] ?? false),
                ], array_values($data['lines'])),
                filled($data['purchase_order_id'] ?? null) ? PurchaseOrder::query()->find($data['purchase_order_id']) : null,
                filled($data['work_order_id'] ?? null) ? WorkOrder::query()->find($data['work_order_id']) : null,
                filled($data['property_id'] ?? null) ? Property::query()->find($data['property_id']) : null,
            );
        } catch (ProcurementActionRefused|InvalidJournalEntry $exception) {
            Notification::make()->title('Bill not recorded')->body($exception->getMessage())->danger()->persistent()->send();

            throw new Halt;
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return "Bill {$this->getRecord()->number} recorded";
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
