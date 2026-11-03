<?php

namespace App\Filament\Resources\Units\Pages;

use App\Enums\UnitStatus;
use App\Filament\Resources\Leases\LeaseResource;
use App\Filament\Resources\Units\UnitResource;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\Unit;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewUnit extends ViewRecord
{
    protected static string $resource = UnitResource::class;

    public function getTitle(): string
    {
        return 'Unit '.$this->getRecord()->fullCode();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('let')
                ->label('Let unit')
                ->icon(Heroicon::OutlinedKey)
                ->color('success')
                ->visible(fn (Unit $record): bool => $record->status === UnitStatus::Vacant && LeaseResource::canCreate())
                ->url(fn (Unit $record): string => LeaseResource::getUrl('create', ['unit' => $record->id])),
            Action::make('logWorkOrder')
                ->label('Log work order')
                ->icon(Heroicon::OutlinedWrenchScrewdriver)
                ->color('gray')
                ->visible(fn (): bool => WorkOrderResource::canCreate())
                ->url(fn (Unit $record): string => WorkOrderResource::getUrl('create', ['unit' => $record->id])),
            EditAction::make(),
        ];
    }
}
