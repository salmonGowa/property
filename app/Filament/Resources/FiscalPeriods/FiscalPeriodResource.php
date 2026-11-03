<?php

namespace App\Filament\Resources\FiscalPeriods;

use App\Enums\FiscalPeriodStatus;
use App\Filament\Resources\FiscalPeriods\Pages\ManageFiscalPeriods;
use App\Models\FiscalPeriod;
use App\Services\Ledger\FiscalPeriodService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Periods are created a year at a time and only ever closed or reopened, never edited.
 */
class FiscalPeriodResource extends Resource
{
    protected static ?string $model = FiscalPeriod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_on', 'desc')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('starts_on')
                    ->date()
                    ->sortable(),
                TextColumn::make('ends_on')
                    ->date(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('closed_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextColumn::make('closer.name')
                    ->label('Closed by')
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(FiscalPeriodStatus::class),
            ])
            ->recordActions([
                Action::make('close')
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('No further journal entries can be posted into a closed period.')
                    ->authorize('close')
                    ->action(fn (FiscalPeriod $record, FiscalPeriodService $fiscalPeriods) => $fiscalPeriods->close($record, Auth::user()))
                    ->successNotificationTitle('Period closed'),
                Action::make('reopen')
                    ->icon(Heroicon::OutlinedLockOpen)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Reopening allows postings that change figures already reported for this period.')
                    ->authorize('reopen')
                    ->action(fn (FiscalPeriod $record, FiscalPeriodService $fiscalPeriods) => $fiscalPeriods->reopen($record))
                    ->successNotificationTitle('Period reopened'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFiscalPeriods::route('/'),
        ];
    }
}
