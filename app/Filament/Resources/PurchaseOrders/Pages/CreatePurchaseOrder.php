<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\Procurement\ProcurementActionRefused;
use App\Services\Procurement\PurchaseOrderService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreatePurchaseOrder extends CreateRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected static bool $canCreateAnother = false;

    protected static ?string $title = 'New purchase order';

    public bool $submitForApproval = false;

    protected function afterFill(): void
    {
        $state = $this->form->getRawState();

        if ($vendor = Vendor::query()->find(request()->query('vendor'))) {
            $state['vendor_id'] = $vendor->id;
        }

        if ($workOrder = WorkOrder::query()->find(request()->query('work_order'))) {
            $state['work_order_id'] = $workOrder->id;
            $state['property_id'] = $workOrder->property_id;
            $state['vendor_id'] ??= $workOrder->vendor_id;
            $state['description'] = "{$workOrder->number}: {$workOrder->title}";
        }

        $this->form->fill($state);
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('createAndSubmit')
                ->label('Save and submit for approval')
                ->action(function (): void {
                    $this->submitForApproval = true;
                    $this->create();
                })
                ->keyBindings(['mod+s']),
            $this->getCreateFormAction()->label('Save as draft')->color('gray'),
            $this->getCancelFormAction(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $orders = app(PurchaseOrderService::class);

        try {
            $order = $orders->create($data, array_values($data['lines']), Auth::user());

            if ($this->submitForApproval) {
                $orders->submit($order);
            }

            return $order;
        } catch (ProcurementActionRefused $exception) {
            $this->submitForApproval = false;
            Notification::make()->title('Purchase order not saved')->body($exception->getMessage())->danger()->persistent()->send();

            throw new Halt;
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return $this->submitForApproval
            ? "{$this->getRecord()->number} submitted for approval"
            : "{$this->getRecord()->number} saved as a draft";
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
