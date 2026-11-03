<?php

namespace App\Filament\Resources\Accounts;

use App\Enums\AccountType;
use App\Filament\Resources\Accounts\Pages\ManageAccounts;
use App\Models\Account;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AccountResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $navigationLabel = 'Chart of Accounts';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->required()
                    ->maxLength(20)
                    ->unique(ignoreRecord: true),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('type')
                    ->options(AccountType::class)
                    ->required()
                    ->live()
                    ->disabled(fn (?Account $record): bool => $record?->lines()->exists() ?? false)
                    ->helperText(fn (?Account $record): ?string => $record?->lines()->exists() ? 'Locked because this account has postings.' : null),
                Select::make('parent_id')
                    ->label('Parent (header) account')
                    ->relationship(
                        'parent',
                        'name',
                        fn (Builder $query, Get $get) => $query->where('is_header', true)->where('type', $get('type')),
                    )
                    ->getOptionLabelFromRecordUsing(fn (Account $record): string => "{$record->code} {$record->name}"),
                Toggle::make('is_header')
                    ->label('Header account (groups other accounts, cannot be posted to)')
                    ->disabled(fn (?Account $record): bool => $record?->lines()->exists() ?? false),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
                Textarea::make('description')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->weight(fn (Account $record): ?string => $record->is_header ? 'bold' : null),
                TextColumn::make('type')
                    ->badge(),
                TextColumn::make('parent.name')
                    ->label('Parent')
                    ->toggleable(),
                IconColumn::make('is_header')
                    ->label('Header')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(AccountType::class),
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAccounts::route('/'),
        ];
    }
}
