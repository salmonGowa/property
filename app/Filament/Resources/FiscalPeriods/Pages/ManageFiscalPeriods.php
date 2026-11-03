<?php

namespace App\Filament\Resources\FiscalPeriods\Pages;

use App\Filament\Resources\FiscalPeriods\FiscalPeriodResource;
use App\Models\FiscalPeriod;
use App\Services\Ledger\FiscalPeriodService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageFiscalPeriods extends ManageRecords
{
    protected static string $resource = FiscalPeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openYear')
                ->label('Open fiscal year')
                ->icon(Heroicon::OutlinedPlus)
                ->authorize('create', FiscalPeriod::class)
                ->schema([
                    TextInput::make('year')
                        ->integer()
                        ->required()
                        ->minValue(2000)
                        ->maxValue(2100)
                        ->default(now()->year),
                ])
                ->action(fn (array $data, FiscalPeriodService $fiscalPeriods) => $fiscalPeriods->openYear((int) $data['year']))
                ->successNotificationTitle('Monthly periods created'),
        ];
    }
}
