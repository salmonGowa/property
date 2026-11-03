<?php

namespace App\Filament\Resources\Loans\RelationManagers;

use App\Enums\CovenantMetric;
use App\Models\LoanCovenant;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Financial covenants from the loan agreement, with the last recorded test of each.
 */
class CovenantsRelationManager extends RelationManager
{
    protected static string $relationship = 'covenants';

    /**
     * Editable on the view page too; the policy decides who may change it.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        $metric = fn (Get $get): ?CovenantMetric => $get('metric') instanceof CovenantMetric ? $get('metric') : CovenantMetric::tryFrom((string) $get('metric'));

        return $schema
            ->components([
                Radio::make('metric')
                    ->label('Covenant')
                    ->options(CovenantMetric::class)
                    ->required()
                    ->live(),
                TextInput::make('threshold')
                    ->label(fn (Get $get): string => $metric($get)?->isMinimum() === false ? 'Must not exceed' : 'Must be at least')
                    ->numeric()
                    ->minValue(0)
                    ->suffix(fn (Get $get): string => $metric($get) === CovenantMetric::Dscr ? 'x' : '%')
                    ->placeholder(fn (Get $get): string => match ($metric($get)) {
                        CovenantMetric::Dscr => 'e.g. 1.25',
                        CovenantMetric::Ltv => 'e.g. 65',
                        default => 'e.g. 12',
                    })
                    ->required(),
                Toggle::make('is_active')->label('Active')->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('latestTest'))
            ->columns([
                TextColumn::make('requirement')->state(fn (LoanCovenant $record): string => $record->requirement())->weight('semibold'),
                TextColumn::make('latestTest.actual')
                    ->label('Last recorded')
                    ->formatStateUsing(fn (LoanCovenant $record): string => $record->metric->format($record->latestTest?->actual))
                    ->description(fn (LoanCovenant $record): ?string => $record->latestTest?->tested_on->format('j M Y'))
                    ->placeholder('Not tested yet'),
                TextColumn::make('result')
                    ->state(fn (LoanCovenant $record): ?string => match ($record->latestTest?->passed) {
                        true => 'Met',
                        false => 'Breached',
                        null => $record->latestTest ? 'Cannot measure' : null,
                    })
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'Met' => 'success',
                        'Breached' => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('-'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->headerActions([
                CreateAction::make()->label('Add covenant'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No covenants')
            ->emptyStateDescription('Add the DSCR, LTV and debt yield tests in the facility letter so breaches are flagged early.');
    }
}
