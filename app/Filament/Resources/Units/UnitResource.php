<?php

namespace App\Filament\Resources\Units;

use App\Enums\UnitStatus;
use App\Enums\UnitType;
use App\Filament\Resources\Leases\LeaseResource;
use App\Filament\Resources\Properties\PropertyResource;
use App\Filament\Resources\Units\Pages\CreateUnit;
use App\Filament\Resources\Units\Pages\EditUnit;
use App\Filament\Resources\Units\Pages\ListUnits;
use App\Filament\Resources\Units\Pages\ViewUnit;
use App\Filament\Resources\Units\RelationManagers\LeasesRelationManager;
use App\Filament\Resources\Units\RelationManagers\WorkOrdersRelationManager;
use App\Models\Unit;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;
use UnitEnum;

class UnitResource extends Resource
{
    protected static ?string $model = Unit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static string|UnitEnum|null $navigationGroup = 'Property Management';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'property.name', 'property.code'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record->fullCode();
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['Status' => $record->status->getLabel(), 'Property' => $record->property->name];
    }

    public static function getNavigationBadge(): ?string
    {
        $vacant = Unit::query()->where('status', UnitStatus::Vacant)->count();

        return $vacant > 0 ? (string) $vacant : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Vacant units';
    }

    public static function form(Schema $schema): Schema
    {
        $isResidential = fn (Get $get): bool => in_array($get('type'), [UnitType::Bedsitter, UnitType::Studio, UnitType::Apartment, UnitType::House, 'bedsitter', 'studio', 'apartment', 'house'], true);

        return $schema
            ->columns(2)
            ->components([
                Section::make('Unit')
                    ->columns(2)
                    ->schema([
                        Select::make('property_id')
                            ->relationship('property', 'name', fn (Builder $query) => $query->where('is_active', true))
                            ->required()
                            ->searchable()
                            ->preload()
                            ->hiddenOn(RelationManager::class),
                        TextInput::make('code')
                            ->label('Unit number')
                            ->required()
                            ->maxLength(30)
                            ->placeholder('e.g. A-101')
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule, Get $get, Component $livewire) => $rule->where('property_id', $livewire instanceof RelationManager ? $livewire->getOwnerRecord()->getKey() : $get('property_id'))),
                        Select::make('type')
                            ->options(UnitType::class)
                            ->required()
                            ->live()
                            ->native(false),
                        Select::make('status')
                            ->options([
                                UnitStatus::Vacant->value => UnitStatus::Vacant->getLabel(),
                                UnitStatus::Reserved->value => UnitStatus::Reserved->getLabel(),
                                UnitStatus::Maintenance->value => UnitStatus::Maintenance->getLabel(),
                            ])
                            ->default(UnitStatus::Vacant->value)
                            ->required()
                            ->disabled(fn (?Unit $record): bool => $record?->status === UnitStatus::Occupied)
                            ->helperText(fn (?Unit $record): string => $record?->status === UnitStatus::Occupied
                                ? 'Occupied by an active lease. Terminate the lease to change this.'
                                : 'Occupied is set automatically when a lease is activated.'),
                        TextInput::make('floor')
                            ->maxLength(20)
                            ->placeholder('e.g. Ground, 1st, Mezzanine'),
                        TextInput::make('area_sqft')
                            ->label('Area')
                            ->numeric()
                            ->minValue(0)
                            ->suffix('sq ft'),
                        TextInput::make('bedrooms')
                            ->integer()
                            ->minValue(0)
                            ->maxValue(20)
                            ->visible($isResidential),
                        TextInput::make('bathrooms')
                            ->integer()
                            ->minValue(0)
                            ->maxValue(20)
                            ->visible($isResidential),
                    ]),
                Section::make('Asking terms')
                    ->description('Used as defaults when you draft a lease for this unit.')
                    ->schema([
                        TextInput::make('market_rent')
                            ->label('Monthly rent')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('KES')
                            ->default(0)
                            ->required(),
                        TextInput::make('service_charge')
                            ->label('Monthly service charge')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('KES')
                            ->default(0),
                        Textarea::make('notes')
                            ->rows(3),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Unit')
                    ->columnSpan(2)
                    ->columns(3)
                    ->schema([
                        TextEntry::make('code')->label('Unit')->state(fn (Unit $record): string => $record->fullCode())->weight('bold'),
                        TextEntry::make('property.name')->url(fn (Unit $record): string => PropertyResource::getUrl('view', ['record' => $record->property_id])),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('type'),
                        TextEntry::make('floor')->placeholder('-'),
                        TextEntry::make('area_sqft')->label('Area')->suffix(' sq ft')->placeholder('-'),
                        TextEntry::make('bedrooms')->placeholder('-'),
                        TextEntry::make('market_rent')->label('Asking rent')->money('KES'),
                        TextEntry::make('service_charge')->money('KES'),
                        TextEntry::make('notes')->placeholder('-')->columnSpanFull(),
                    ]),
                Section::make('Current lease')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('activeLease.tenant.name')
                            ->label('Tenant')
                            ->placeholder('Vacant')
                            ->url(fn (Unit $record): ?string => $record->activeLease ? LeaseResource::getUrl('view', ['record' => $record->activeLease]) : null),
                        TextEntry::make('activeLease.rent_amount')->label('Rent')->money('KES')->placeholder('-'),
                        TextEntry::make('activeLease.end_date')->label('Ends')->date()->placeholder('Open-ended'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['property', 'activeLease.tenant']))
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')
                    ->label('Unit')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->description(fn (Unit $record): string => $record->property->name)
                    ->hidden(fn ($livewire): bool => $livewire instanceof RelationManager),
                TextColumn::make('unit_code')
                    ->label('Unit')
                    ->state(fn (Unit $record): string => $record->code)
                    ->weight('semibold')
                    ->visible(fn ($livewire): bool => $livewire instanceof RelationManager),
                TextColumn::make('type'),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('activeLease.tenant.name')
                    ->label('Tenant')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('market_rent')
                    ->label('Asking rent')
                    ->money('KES')
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('activeLease.end_date')
                    ->label('Lease ends')
                    ->date()
                    ->placeholder('-')
                    ->color(fn (Unit $record): ?string => $record->activeLease?->end_date?->lt(today()->addDays(60)) ? 'warning' : null),
                TextColumn::make('bedrooms')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('area_sqft')
                    ->label('Area (sq ft)')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('property')
                    ->relationship('property', 'name')
                    ->searchable()
                    ->preload()
                    ->hidden(fn ($livewire): bool => $livewire instanceof RelationManager),
                SelectFilter::make('status')->options(UnitStatus::class),
                SelectFilter::make('type')->options(UnitType::class),
            ])
            ->recordActions([
                Action::make('let')
                    ->label('Let unit')
                    ->icon(Heroicon::OutlinedKey)
                    ->color('success')
                    ->visible(fn (Unit $record): bool => $record->status === UnitStatus::Vacant && LeaseResource::canCreate())
                    ->url(fn (Unit $record): string => LeaseResource::getUrl('create', ['unit' => $record->id])),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->emptyStateHeading('No units yet');
    }

    public static function getRelations(): array
    {
        return [
            LeasesRelationManager::class,
            WorkOrdersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUnits::route('/'),
            'create' => CreateUnit::route('/create'),
            'view' => ViewUnit::route('/{record}'),
            'edit' => EditUnit::route('/{record}/edit'),
        ];
    }
}
