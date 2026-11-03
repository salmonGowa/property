<?php

namespace App\Filament\Resources\Properties\RelationManagers;

use App\Models\PropertyValuation;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Open-market valuations; the latest one is the security value used for loan-to-value.
 */
class ValuationsRelationManager extends RelationManager
{
    protected static string $relationship = 'valuations';

    /**
     * Editable on the view page too; the policy decides who may change it.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                DatePicker::make('valued_on')->label('Valuation date')->maxDate(today())->default(today())->required(),
                TextInput::make('market_value')->label('Open market value')->numeric()->minValue(1)->rule('decimal:0,2')->prefix('KES')->required(),
                TextInput::make('valuer')->label('Valuer')->maxLength(255)->placeholder('Registered valuer or firm'),
                Textarea::make('notes')->rows(2)->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('valued_on', 'desc')
            ->columns([
                TextColumn::make('valued_on')->label('Date')->date(),
                TextColumn::make('market_value')->label('Value')->money('KES')->weight('semibold'),
                TextColumn::make('change')
                    ->state(function (PropertyValuation $record): ?string {
                        $previous = $record->property->valuations()->whereDate('valued_on', '<', $record->valued_on)->first();

                        return $previous ? number_format(((float) $record->market_value / (float) $previous->market_value - 1) * 100, 1).'%' : null;
                    })
                    ->placeholder('-'),
                TextColumn::make('valuer')->placeholder('-'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Record valuation')
                    ->mutateDataUsing(fn (array $data): array => [...$data, 'created_by' => Auth::id()]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No valuations')
            ->emptyStateDescription('Record valuations so loan-to-value covenants can be measured.');
    }
}
