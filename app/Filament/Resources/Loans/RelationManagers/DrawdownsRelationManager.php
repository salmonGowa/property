<?php

namespace App\Filament\Resources\Loans\RelationManagers;

use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Models\LoanDrawdown;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DrawdownsRelationManager extends RelationManager
{
    protected static string $relationship = 'drawdowns';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('drawn_on')->date(),
                TextColumn::make('amount')->money('KES')->alignEnd()->summarize(Sum::make()->money('KES')->label('Drawn')),
                TextColumn::make('fees')->money('KES')->alignEnd(),
                TextColumn::make('bankAccount.name')->label('Received into'),
                TextColumn::make('reference')->placeholder('-'),
                TextColumn::make('journalEntry.number')
                    ->label('Posted as')
                    ->url(fn (LoanDrawdown $record): ?string => $record->journal_entry_id ? JournalEntryResource::getUrl('view', ['record' => $record->journal_entry_id]) : null),
            ])
            ->emptyStateHeading('Nothing drawn yet');
    }
}
