<?php

namespace App\Filament\Resources\Properties;

use App\Enums\KenyaCounty;
use App\Enums\PropertyType;
use App\Enums\UnitStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\CreateProperty;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Filament\Resources\Properties\Pages\ListProperties;
use App\Filament\Resources\Properties\Pages\ViewProperty;
use App\Filament\Resources\Properties\RelationManagers\LeasesRelationManager;
use App\Filament\Resources\Properties\RelationManagers\UnitsRelationManager;
use App\Filament\Resources\Properties\RelationManagers\ValuationsRelationManager;
use App\Filament\Resources\Properties\RelationManagers\WorkOrdersRelationManager;
use App\Models\Lease;
use App\Models\Property;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;
use UnitEnum;

class PropertyResource extends Resource
{
    protected static ?string $model = Property::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Property Management';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'name', 'town', 'lr_number'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['Code' => $record->code, 'Location' => trim("{$record->town}, {$record->county?->getLabel()}", ', ')];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Property details')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g. Kilimani Heights'),
                        TextInput::make('code')
                            ->required()
                            ->maxLength(20)
                            ->unique(ignoreRecord: true)
                            ->placeholder('e.g. KIL-01')
                            ->helperText('Short code shown on invoices and unit references.')
                            ->dehydrateStateUsing(fn (string $state): string => strtoupper(trim($state))),
                        Select::make('type')
                            ->options(PropertyType::class)
                            ->required()
                            ->native(false),
                        Select::make('manager_id')
                            ->label('Property manager')
                            ->relationship('manager', 'name', fn (Builder $query) => $query->whereIn('role', [UserRole::PropertyManager, UserRole::Admin])->where('is_active', true))
                            ->searchable()
                            ->preload(),
                        Select::make('penalty_rule_id')
                            ->label('Late payment penalty rule')
                            ->relationship('penaltyRule', 'name', fn (Builder $query) => $query->where('is_active', true))
                            ->placeholder('Company default')
                            ->helperText('Applies to every lease here unless a lease sets its own.'),
                        Textarea::make('description')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                Section::make('Photo')
                    ->columnSpan(1)
                    ->schema([
                        FileUpload::make('photo_path')
                            ->hiddenLabel()
                            ->image()
                            ->imageEditor()
                            ->disk('public')
                            ->directory('properties')
                            ->maxSize(4096),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->helperText('Inactive properties are hidden from lease and billing screens.')
                            ->default(true),
                    ]),
                Section::make('Location')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        Select::make('county')
                            ->options(KenyaCounty::class)
                            ->required()
                            ->searchable()
                            ->default(KenyaCounty::Nairobi),
                        TextInput::make('town')
                            ->maxLength(255)
                            ->placeholder('e.g. Kilimani'),
                        Textarea::make('address')
                            ->rows(2)
                            ->placeholder('Street, road or landmark'),
                        TextInput::make('lr_number')
                            ->label('L.R. / title number')
                            ->maxLength(255)
                            ->placeholder('e.g. Nairobi/Block 1/234'),
                    ]),
                Section::make('Building')
                    ->columnSpan(1)
                    ->schema([
                        TextInput::make('total_area_sqft')
                            ->label('Total lettable area')
                            ->numeric()
                            ->minValue(0)
                            ->suffix('sq ft'),
                        TextInput::make('year_built')
                            ->integer()
                            ->minValue(1900)
                            ->maxValue((int) date('Y') + 5),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make()
                    ->columnSpan(2)
                    ->columns(3)
                    ->schema([
                        TextEntry::make('name')->size('lg')->weight('bold')->columnSpan(2),
                        TextEntry::make('code')->badge()->color('gray'),
                        TextEntry::make('type')->badge(),
                        TextEntry::make('location')
                            ->state(fn (Property $record): string => trim("{$record->town}, {$record->county->getLabel()}", ', ')),
                        TextEntry::make('manager.name')->label('Property manager')->placeholder('Not assigned'),
                        TextEntry::make('address')->placeholder('-'),
                        TextEntry::make('lr_number')->label('L.R. number')->placeholder('-'),
                        IconEntry::make('is_active')->label('Active')->boolean(),
                        TextEntry::make('description')->placeholder('-')->columnSpanFull(),
                    ]),
                ImageEntry::make('photo_path')
                    ->hiddenLabel()
                    ->disk('public')
                    ->imageHeight('14rem')
                    ->columnSpan(1)
                    ->visible(fn (Property $record): bool => filled($record->photo_path)),
                Grid::make(5)
                    ->columnSpanFull()
                    ->schema([
                        self::stat('Units', fn (Property $record): string => (string) $record->units()->count()),
                        self::stat('Occupied', fn (Property $record): string => (string) $record->units()->where('status', UnitStatus::Occupied)->count()),
                        self::stat('Vacant', fn (Property $record): string => (string) $record->units()->where('status', UnitStatus::Vacant)->count()),
                        self::stat('Occupancy', fn (Property $record): string => $record->occupancyRate().'%'),
                        self::stat('Monthly rent roll', fn (Property $record): string => 'KES '.Number::format((float) Lease::query()->active()->whereIn('unit_id', $record->units()->select('id'))->sum('rent_amount'), 2)),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('manager')
                ->withCount(['units', 'units as occupied_units_count' => fn (Builder $units) => $units->where('status', UnitStatus::Occupied)]))
            ->defaultSort('name')
            ->columns([
                ImageColumn::make('photo_path')
                    ->label('')
                    ->disk('public')
                    ->square(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->description(fn (Property $record): string => $record->code),
                TextColumn::make('type')
                    ->badge(),
                TextColumn::make('town')
                    ->label('Location')
                    ->formatStateUsing(fn (Property $record): string => trim("{$record->town}, {$record->county->getLabel()}", ', '))
                    ->searchable(),
                TextColumn::make('units_count')
                    ->label('Units')
                    ->sortable()
                    ->alignCenter(),
                TextColumn::make('occupancy')
                    ->state(fn (Property $record): int => $record->occupancyRate())
                    ->formatStateUsing(fn (int $state): string => "{$state}%")
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state >= 90 => 'success',
                        $state >= 70 => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('manager.name')
                    ->label('Manager')
                    ->placeholder('-')
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')->options(PropertyType::class),
                SelectFilter::make('county')->options(KenyaCounty::class)->searchable(),
                SelectFilter::make('manager')->relationship('manager', 'name'),
                TernaryFilter::make('is_active')->label('Active')->default(true),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->emptyStateHeading('No properties yet')
            ->emptyStateDescription('Add your first building to start letting units.');
    }

    public static function getRelations(): array
    {
        return [
            UnitsRelationManager::class,
            LeasesRelationManager::class,
            WorkOrdersRelationManager::class,
            ValuationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProperties::route('/'),
            'create' => CreateProperty::route('/create'),
            'view' => ViewProperty::route('/{record}'),
            'edit' => EditProperty::route('/{record}/edit'),
        ];
    }

    private static function stat(string $label, Closure $state): Section
    {
        return Section::make()
            ->compact()
            ->schema([
                TextEntry::make(str($label)->snake()->toString())
                    ->label($label)
                    ->state($state)
                    ->size('lg')
                    ->weight('bold'),
            ]);
    }
}
