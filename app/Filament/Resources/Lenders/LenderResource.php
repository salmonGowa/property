<?php

namespace App\Filament\Resources\Lenders;

use App\Filament\Resources\Lenders\Pages\ManageLenders;
use App\Models\Lender;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class LenderResource extends Resource
{
    protected static ?string $model = Lender::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Debt & Financing';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')->required()->maxLength(255)->placeholder('e.g. KCB Bank Kenya'),
                TextInput::make('branch')->maxLength(255)->placeholder('e.g. Corporate Banking, Upper Hill'),
                TextInput::make('contact_person')->label('Relationship manager')->maxLength(255),
                TextInput::make('phone')->tel()->maxLength(30),
                TextInput::make('email')->email()->maxLength(255),
                TextInput::make('interest_withholding_rate')
                    ->label('Withholding tax on interest')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(30)
                    ->default(0)
                    ->suffix('%')
                    ->required()
                    ->helperText('Leave at 0 for Kenyan banks. Interest paid to other lenders (e.g. mezzanine funds, shareholders) usually has 15% withheld.'),
                Textarea::make('notes')->rows(2)->columnSpanFull(),
                Toggle::make('is_active')->label('Active')->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->weight('semibold')->description(fn (Lender $record): ?string => $record->branch),
                TextColumn::make('contact_person')->label('Relationship manager')->description(fn (Lender $record): ?string => $record->phone)->placeholder('-'),
                TextColumn::make('loans_count')->label('Loans')->counts('loans')->alignCenter(),
                TextColumn::make('interest_withholding_rate')->label('WHT on interest')->suffix('%')->alignEnd(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([
                EditAction::make()->slideOver(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No lenders yet')
            ->emptyStateDescription('Add the banks and other financiers you borrow from.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageLenders::route('/'),
        ];
    }
}
