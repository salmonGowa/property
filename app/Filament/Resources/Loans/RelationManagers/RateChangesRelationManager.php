<?php

namespace App\Filament\Resources\Loans\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RateChangesRelationManager extends RelationManager
{
    protected static string $relationship = 'rateChanges';

    protected static ?string $title = 'Rate history';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('effective_from', 'desc')
            ->columns([
                TextColumn::make('effective_from')->date(),
                TextColumn::make('previous_rate')->label('From')->suffix('%'),
                TextColumn::make('interest_rate')->label('To')->suffix('%')->weight('semibold'),
                TextColumn::make('base_rate')->suffix('%')->placeholder('-'),
                TextColumn::make('margin')->suffix('%')->placeholder('-'),
                TextColumn::make('reason')->placeholder('-'),
                TextColumn::make('creator.name')->label('Recorded by')->placeholder('-'),
            ])
            ->emptyStateHeading('No rate changes');
    }
}
