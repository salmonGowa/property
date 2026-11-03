<?php

namespace App\Filament\Resources\Properties\Pages;

use App\Filament\Resources\Properties\PropertyResource;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\Property;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewProperty extends ViewRecord
{
    protected static string $resource = PropertyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('logWorkOrder')
                ->label('Log work order')
                ->icon(Heroicon::OutlinedWrenchScrewdriver)
                ->color('gray')
                ->visible(fn (): bool => WorkOrderResource::canCreate())
                ->url(fn (Property $record): string => WorkOrderResource::getUrl('create', ['property' => $record->id])),
            EditAction::make(),
        ];
    }
}
