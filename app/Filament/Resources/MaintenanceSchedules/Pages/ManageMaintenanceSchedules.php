<?php

namespace App\Filament\Resources\MaintenanceSchedules\Pages;

use App\Filament\Resources\MaintenanceSchedules\MaintenanceScheduleResource;
use App\Models\MaintenanceSchedule;
use App\Services\Maintenance\MaintenanceScheduleService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageMaintenanceSchedules extends ManageRecords
{
    protected static string $resource = MaintenanceScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('raiseDue')
                ->label('Raise due work orders')
                ->icon(Heroicon::OutlinedPlay)
                ->color('gray')
                ->authorize('create', MaintenanceSchedule::class)
                ->requiresConfirmation()
                ->modalDescription('Raises work orders for every schedule within its lead time. This also runs automatically every morning.')
                ->action(function (MaintenanceScheduleService $schedules): void {
                    $raised = $schedules->raiseDue(today());

                    Notification::make()
                        ->title($raised->count().' work order(s) raised')
                        ->body($raised->map(fn ($workOrder): string => "{$workOrder->number} {$workOrder->title}")->implode(', ') ?: null)
                        ->success()
                        ->send();
                }),
            CreateAction::make()->label('New schedule')->slideOver(),
        ];
    }
}
