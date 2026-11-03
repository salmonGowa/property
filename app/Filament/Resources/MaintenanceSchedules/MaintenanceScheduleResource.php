<?php

namespace App\Filament\Resources\MaintenanceSchedules;

use App\Enums\BillingFrequency;
use App\Enums\WorkOrderCategory;
use App\Enums\WorkOrderPriority;
use App\Filament\Resources\MaintenanceSchedules\Pages\ManageMaintenanceSchedules;
use App\Filament\Resources\Vendors\VendorResource;
use App\Models\MaintenanceSchedule;
use App\Models\Property;
use App\Models\Unit;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use UnitEnum;

class MaintenanceScheduleResource extends Resource
{
    protected static ?string $model = MaintenanceSchedule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Maintenance';

    protected static ?string $navigationLabel = 'Planned Maintenance';

    protected static ?string $modelLabel = 'maintenance schedule';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('title')->required()->maxLength(255)->placeholder('e.g. Quarterly lift servicing')->columnSpanFull(),
                Select::make('property_id')
                    ->label('Property')
                    ->options(fn (): array => Property::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('unit_id', null)),
                Select::make('unit_id')
                    ->label('Unit (blank for the whole building)')
                    ->options(fn (Get $get): array => Unit::query()->where('property_id', $get('property_id'))->orderBy('code')->pluck('code', 'id')->all())
                    ->searchable(),
                Select::make('category')->options(WorkOrderCategory::class)->required()->live(),
                Select::make('priority')->options(WorkOrderPriority::class)->default(WorkOrderPriority::Normal->value)->required(),
                Select::make('vendor_id')
                    ->label('Usual supplier')
                    ->options(fn (Get $get): array => VendorResource::options($get('category') instanceof WorkOrderCategory ? $get('category')->value : $get('category')))
                    ->searchable()
                    ->helperText('Work orders are assigned to them automatically.'),
                Select::make('frequency')->options(BillingFrequency::class)->default(BillingFrequency::Quarterly->value)->required()->live(),
                DatePicker::make('next_due_on')->label('Next visit due')->required()->live(),
                TextInput::make('lead_days')
                    ->label('Raise the work order')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(90)
                    ->default(7)
                    ->suffix('days before')
                    ->required()
                    ->live(onBlur: true),
                Text::make(function (Get $get): string {
                    if (blank($get('next_due_on'))) {
                        return 'Set the next visit to see when the work order is raised.';
                    }

                    $due = Carbon::parse($get('next_due_on'));
                    $frequency = $get('frequency') instanceof BillingFrequency ? $get('frequency') : BillingFrequency::tryFrom((string) $get('frequency'));

                    return 'A work order is raised on '.$due->copy()->subDays((int) $get('lead_days'))->format('j M Y').' for the visit due '.$due->format('j M Y')
                        .($frequency ? ', then '.strtolower($frequency->getLabel()).' after that (next '.$due->copy()->addMonthsNoOverflow($frequency->months())->format('j M Y').').' : '.');
                })->color('gray')->columnSpanFull(),
                Textarea::make('description')->label('Instructions for the supplier')->rows(3)->columnSpanFull(),
                Toggle::make('is_active')->label('Active')->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['property', 'unit', 'vendor']))
            ->defaultSort('next_due_on')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->weight('semibold')
                    ->description(fn (MaintenanceSchedule $record): string => $record->property->name.($record->unit ? " / {$record->unit->code}" : '')),
                TextColumn::make('category'),
                TextColumn::make('frequency'),
                TextColumn::make('vendor.name')->label('Supplier')->placeholder('Assign when raised'),
                TextColumn::make('next_due_on')->label('Next visit')->date()->sortable()->description(fn (MaintenanceSchedule $record): string => 'Raised '.$record->next_due_on->copy()->subDays($record->lead_days)->format('j M')),
                TextColumn::make('last_raised_on')->label('Last raised')->date()->placeholder('Never')->toggleable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('property')->relationship('property', 'name')->searchable()->preload(),
            ])
            ->recordActions([
                EditAction::make()->slideOver(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No planned maintenance')
            ->emptyStateDescription('Schedule recurring jobs such as lift servicing, fumigation, tank cleaning or fire equipment checks. Work orders are raised automatically each morning.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMaintenanceSchedules::route('/'),
        ];
    }
}
