<?php

namespace App\Filament\Resources\Loans\RelationManagers;

use App\Models\LoanInstalment;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The repayment schedule: paid instalments as history, unpaid ones as currently projected.
 */
class InstalmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'instalments';

    protected static ?string $title = 'Repayment schedule';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('sequence')
            ->paginated([12, 24, 60, 'all'])
            ->defaultPaginationPageOption(12)
            ->columns([
                TextColumn::make('sequence')->label('#'),
                TextColumn::make('due_date')
                    ->label('Due')
                    ->date()
                    ->color(fn (LoanInstalment $record): ?string => $record->isOverdue() ? 'danger' : null),
                TextColumn::make('opening_balance')->label('Opening')->money('KES')->alignEnd(),
                TextColumn::make('principal')->money('KES')->alignEnd()->summarize(Sum::make()->money('KES')->label('')),
                TextColumn::make('interest')->money('KES')->alignEnd()->summarize(Sum::make()->money('KES')->label('')),
                TextColumn::make('total')->label('Payment')->money('KES')->alignEnd()->weight('semibold')->summarize(Sum::make()->money('KES')->label('')),
                TextColumn::make('closing_balance')->label('Closing')->money('KES')->alignEnd(),
                TextColumn::make('interest_rate')->label('Rate')->suffix('%')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('state')
                    ->label('Status')
                    ->state(fn (LoanInstalment $record): string => match (true) {
                        $record->isPaid() => 'Paid '.$record->repayment->paid_on->format('j M Y'),
                        $record->isOverdue() => 'Overdue',
                        default => 'Scheduled',
                    })
                    ->badge()
                    ->color(fn (LoanInstalment $record): string => match (true) {
                        $record->isPaid() => 'success',
                        $record->isOverdue() => 'danger',
                        default => 'gray',
                    }),
            ])
            ->emptyStateHeading('No schedule yet')
            ->emptyStateDescription('The schedule is built when the first drawdown is recorded.');
    }
}
