<?php

namespace App\Filament\Resources\WorkOrders\Pages;

use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Unit;
use App\Services\Maintenance\WorkOrderService;
use App\Services\Procurement\ProcurementActionRefused;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateWorkOrder extends CreateRecord
{
    protected static string $resource = WorkOrderResource::class;

    protected static ?string $title = 'Log work order';

    protected function afterFill(): void
    {
        $state = $this->form->getRawState();

        if ($unit = Unit::query()->find(request()->query('unit'))) {
            $state['property_id'] = $unit->property_id;
            $state['unit_id'] = $unit->id;
            $state['tenant_id'] = Lease::query()->active()->where('unit_id', $unit->id)->value('tenant_id');
        } elseif ($property = Property::query()->find(request()->query('property'))) {
            $state['property_id'] = $property->id;
        }

        $this->form->fill($state);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(WorkOrderService::class)->log($data, Auth::user());
        } catch (ProcurementActionRefused $exception) {
            Notification::make()->title('Work order not logged')->body($exception->getMessage())->danger()->persistent()->send();

            throw new Halt;
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        $workOrder = $this->getRecord();

        return "{$workOrder->number} logged, due {$workOrder->due_on->format('j M')}";
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
