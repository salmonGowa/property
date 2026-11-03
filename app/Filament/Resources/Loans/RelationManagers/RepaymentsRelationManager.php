<?php

namespace App\Filament\Resources\Loans\RelationManagers;

use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Models\LoanRepayment;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RepaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'repayments';

    protected static ?string $title = 'Payments to the lender';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('paid_on', 'desc')
            ->columns([
                TextColumn::make('paid_on')->date(),
                TextColumn::make('kind')
                    ->state(fn (LoanRepayment $record): string => $record->is_prepayment ? 'Prepayment' : 'Instalment '.$record->instalment?->sequence)
                    ->badge()
                    ->color(fn (LoanRepayment $record): string => $record->is_prepayment ? 'info' : 'gray'),
                TextColumn::make('principal')->money('KES')->alignEnd()->summarize(Sum::make()->money('KES')->label('')),
                TextColumn::make('interest')->money('KES')->alignEnd()->summarize(Sum::make()->money('KES')->label('')),
                TextColumn::make('withholding_tax')->label('WHT')->money('KES')->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('amount_paid')->label('Paid')->money('KES')->alignEnd()->weight('semibold'),
                TextColumn::make('reference')->placeholder('-'),
                TextColumn::make('journalEntry.number')
                    ->label('Posted as')
                    ->url(fn (LoanRepayment $record): ?string => $record->journal_entry_id ? JournalEntryResource::getUrl('view', ['record' => $record->journal_entry_id]) : null),
            ])
            ->emptyStateHeading('No payments yet');
    }
}
