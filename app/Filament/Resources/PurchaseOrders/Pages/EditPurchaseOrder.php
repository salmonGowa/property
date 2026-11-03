<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Services\Procurement\ProcurementActionRefused;
use App\Services\Procurement\PurchaseOrderService;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditPurchaseOrder extends EditRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var PurchaseOrder $order */
        $order = $this->getRecord();

        return [...$data, 'lines' => $order->lines->map(fn (PurchaseOrderLine $line): array => [
            'account_id' => $line->account_id,
            'description' => $line->description,
            'quantity' => $line->quantity,
            'unit_price' => $line->unit_price,
            'vat' => bccomp((string) $line->vat_amount, '0', 2) > 0,
        ])->all()];
    }

    /**
     * @param  PurchaseOrder  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(PurchaseOrderService::class)->update($record, $data, array_values($data['lines']));
        } catch (ProcurementActionRefused $exception) {
            Notification::make()->title('Purchase order not saved')->body($exception->getMessage())->danger()->persistent()->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
