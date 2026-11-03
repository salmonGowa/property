<?php

namespace App\Filament\Resources\Leases;

use App\Enums\BillingFrequency;
use App\Enums\LeaseStatus;
use App\Enums\UnitStatus;
use App\Filament\Resources\Leases\Pages\CreateLease;
use App\Filament\Resources\Leases\Pages\EditLease;
use App\Filament\Resources\Leases\Pages\ListLeases;
use App\Filament\Resources\Leases\Pages\ViewLease;
use App\Filament\Resources\Leases\RelationManagers\InvoicesRelationManager;
use App\Filament\Resources\Tenants\TenantResource;
use App\Filament\Resources\Units\UnitResource;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\Leasing\DepositService;
use App\Services\Leasing\RentSchedule;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use UnitEnum;

class LeaseResource extends Resource
{
    protected static ?string $model = Lease::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Property Management';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getGloballySearchableAttributes(): array
    {
        return ['reference', 'tenant.name', 'unit.code'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['Tenant' => $record->tenant->name, 'Unit' => $record->unit->fullCode(), 'Status' => $record->status->getLabel()];
    }

    public static function getNavigationBadge(): ?string
    {
        $expiring = Lease::query()->expiringWithin(60)->count();

        return $expiring > 0 ? (string) $expiring : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Leases expiring within 60 days';
    }

    /**
     * Who and where: the tenant and the unit being let.
     *
     * @return list<Component>
     */
    public static function partiesFields(): array
    {
        return [
            Select::make('tenant_id')
                ->label('Tenant')
                ->relationship('tenant', 'name', fn (Builder $query) => $query->where('is_active', true))
                ->getOptionLabelFromRecordUsing(fn (Tenant $record): string => "{$record->name} ({$record->account_number})")
                ->searchable(['name', 'account_number', 'phone', 'kra_pin'])
                ->preload()
                ->required()
                ->createOptionForm(TenantResource::essentialFields())
                ->createOptionModalHeading('New tenant')
                ->hiddenOn(RelationManager::class),
            Select::make('unit_id')
                ->label('Unit')
                ->options(fn (?Lease $record): array => Unit::query()
                    ->with('property')
                    ->where(fn (Builder $query) => $query->whereIn('status', [UnitStatus::Vacant, UnitStatus::Reserved])->orWhere('id', $record?->unit_id))
                    ->get()
                    ->groupBy(fn (Unit $unit): string => $unit->property->name)
                    ->map(fn ($units) => $units->mapWithKeys(fn (Unit $unit): array => [$unit->id => "{$unit->code} - {$unit->type->getLabel()} (asking KES ".Number::format((float) $unit->market_rent).')'])->all())
                    ->all())
                ->searchable()
                ->required()
                ->live()
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    $unit = $state ? Unit::query()->find($state) : null;

                    if ($unit === null) {
                        return;
                    }

                    $set('rent_amount', $unit->market_rent);
                    $set('service_charge', $unit->service_charge);
                    $set('charge_vat', $unit->type->isCommercial());
                })
                ->helperText('Only vacant and reserved units are listed. Choosing one fills in its asking rent.'),
        ];
    }

    /**
     * @return list<Component>
     */
    public static function termFields(): array
    {
        return [
            DatePicker::make('start_date')
                ->required()
                ->default(today()->addMonthNoOverflow()->startOfMonth())
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => self::applyTermLength($get, $set)),
            Select::make('term_months')
                ->label('Term')
                ->options([12 => '1 year', 24 => '2 years', 36 => '3 years', 60 => '5 years', 72 => '6 years', 0 => 'Periodic (no fixed end)'])
                ->dehydrated(false)
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => self::applyTermLength($get, $set))
                ->helperText('Sets the end date for you; you can still adjust it.'),
            DatePicker::make('end_date')
                ->after('start_date')
                ->helperText('Leave empty for a periodic tenancy.'),
            Select::make('billing_frequency')
                ->options(BillingFrequency::class)
                ->default(BillingFrequency::Monthly)
                ->required()
                ->native(false),
            TextInput::make('due_day')
                ->label('Rent due on day')
                ->integer()
                ->minValue(1)
                ->maxValue(28)
                ->default(5)
                ->required()
                ->suffix('of the month')
                ->helperText('Invoices are issued on the 1st and fall due on this day.'),
        ];
    }

    /**
     * @return list<Component>
     */
    public static function chargeFields(): array
    {
        return [
            TextInput::make('rent_amount')
                ->label('Monthly rent')
                ->numeric()
                ->minValue(0)
                ->prefix('KES')
                ->required()
                ->live(onBlur: true),
            TextInput::make('service_charge')
                ->label('Monthly service charge')
                ->numeric()
                ->minValue(0)
                ->prefix('KES')
                ->default(0),
            TextInput::make('deposit_amount')
                ->label('Security deposit')
                ->numeric()
                ->minValue(0)
                ->prefix('KES')
                ->default(0)
                ->hint(fn (Get $get): ?string => (float) $get('rent_amount') > 0 ? 'Typical: KES '.Number::format((float) $get('rent_amount') * 2).' (2 months)' : null)
                ->helperText('Invoiced once when the lease is activated and held as a liability, not income.'),
            Toggle::make('charge_vat')
                ->label('Charge VAT on rent and service charge')
                ->helperText('Commercial lettings attract VAT at '.config('ledger.vat_rate').'%; residential lettings are exempt.')
                ->inline(false),
            TextInput::make('escalation_rate')
                ->label('Annual escalation')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->default(0)
                ->suffix('%'),
            Select::make('escalation_interval_months')
                ->label('Escalate every')
                ->options([12 => '12 months', 24 => '24 months', 36 => '36 months'])
                ->default(12)
                ->required(),
        ];
    }

    /**
     * @return list<Component>
     */
    public static function penaltyFields(): array
    {
        return [
            Select::make('penalty_rule_id')
                ->label('Penalty rule')
                ->relationship('penaltyRule', 'name', fn (Builder $query) => $query->where('is_active', true))
                ->placeholder('Inherit from property / company default')
                ->helperText('Leave empty to use the property\'s rule, or the company default.')
                ->disabled(fn (Get $get): bool => (bool) $get('penalties_exempt')),
            Toggle::make('penalties_exempt')
                ->label('No late penalties on this lease')
                ->helperText('For special agreements, e.g. government or diplomatic tenants.')
                ->live()
                ->inline(false),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Tenant and unit')->columns(2)->columnSpanFull()->schema(self::partiesFields()),
                Section::make('Term')->columns(2)->schema(self::termFields()),
                Section::make('Charges')->columns(2)->schema(self::chargeFields()),
                Section::make('Late payment')->columns(2)->columnSpanFull()->schema(self::penaltyFields()),
                Textarea::make('notes')->rows(3)->columnSpanFull(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Parties')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('tenant.name')
                            ->label('Tenant')
                            ->weight('bold')
                            ->url(fn (Lease $record): string => TenantResource::getUrl('view', ['record' => $record->tenant_id])),
                        TextEntry::make('tenant.phone')->label('Phone'),
                        TextEntry::make('unit')
                            ->state(fn (Lease $record): string => $record->unit->fullCode())
                            ->url(fn (Lease $record): string => UnitResource::getUrl('view', ['record' => $record->unit_id])),
                        TextEntry::make('unit.property.name')->label('Property'),
                    ]),
                Section::make('Term')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('status')->badge(),
                        TextEntry::make('start_date')->date(),
                        TextEntry::make('end_date')
                            ->date()
                            ->placeholder('Periodic')
                            ->helperText(fn (Lease $record): ?string => $record->status === LeaseStatus::Active && $record->end_date
                                ? ($record->end_date->isPast() ? 'Past end date - renew or terminate' : today()->diffInDays($record->end_date).' days remaining')
                                : null),
                        TextEntry::make('billing_frequency')->label('Billed'),
                        TextEntry::make('due_day')->label('Due day')->formatStateUsing(fn (int $state): string => Number::ordinal($state).' of the month'),
                        TextEntry::make('terminated_on')->date()->visible(fn (Lease $record): bool => $record->terminated_on !== null),
                        TextEntry::make('termination_reason')->visible(fn (Lease $record): bool => $record->terminated_on !== null),
                    ]),
                Section::make('Charges')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('rent_amount')->label('Monthly rent (base)')->money('KES'),
                        TextEntry::make('current_rent')
                            ->label('Monthly rent today')
                            ->state(fn (Lease $record): string => app(RentSchedule::class)->monthlyRentOn($record, today()->max($record->start_date)))
                            ->money('KES')
                            ->visible(fn (Lease $record): bool => (float) $record->escalation_rate > 0),
                        TextEntry::make('service_charge')->money('KES'),
                        TextEntry::make('deposit_amount')->label('Deposit')->money('KES'),
                        IconEntry::make('charge_vat')->label('VAT charged')->boolean(),
                        TextEntry::make('escalation_rate')
                            ->label('Escalation')
                            ->formatStateUsing(fn (Lease $record): string => (float) $record->escalation_rate > 0 ? "{$record->escalation_rate}% every {$record->escalation_interval_months} months" : 'None'),
                    ]),
                Section::make('Late payment & deposit')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('penalty_rule')
                            ->label('Penalty rule in force')
                            ->state(fn (Lease $record): string => $record->effectivePenaltyRule()['rule']?->name ?? 'None')
                            ->helperText(fn (Lease $record): string => $record->effectivePenaltyRule()['source']),
                        TextEntry::make('penalty_terms')
                            ->label('Terms')
                            ->state(fn (Lease $record): string => ucfirst($record->effectivePenaltyRule()['rule']?->summary() ?? 'No late payment penalties')),
                        TextEntry::make('deposit_held')
                            ->label('Deposit held')
                            ->state(fn (Lease $record): string => app(DepositService::class)->heldFor($record))
                            ->money('KES')
                            ->helperText(fn (Lease $record): ?string => (float) $record->deposit_amount > 0 ? 'Agreed: KES '.Number::format((float) $record->deposit_amount, 2) : null),
                    ]),
                TextEntry::make('notes')->placeholder('-')->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['tenant', 'unit.property']))
            ->defaultSort('start_date', 'desc')
            ->columns([
                TextColumn::make('reference')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),
                TextColumn::make('tenant.name')
                    ->searchable()
                    ->hidden(fn ($livewire): bool => $livewire instanceof RelationManager && $livewire->getOwnerRecord() instanceof Tenant),
                TextColumn::make('unit.code')
                    ->label('Unit')
                    ->formatStateUsing(fn (Lease $record): string => $record->unit->fullCode())
                    ->searchable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('start_date')->date()->sortable(),
                TextColumn::make('end_date')
                    ->date()
                    ->sortable()
                    ->placeholder('Periodic')
                    ->color(fn (Lease $record): ?string => $record->status === LeaseStatus::Active && $record->end_date?->lte(today()->addDays(60)) ? 'warning' : null)
                    ->icon(fn (Lease $record): ?Heroicon => $record->status === LeaseStatus::Active && $record->end_date?->lte(today()->addDays(60)) ? Heroicon::OutlinedExclamationTriangle : null),
                TextColumn::make('rent_amount')
                    ->label('Monthly rent')
                    ->money('KES')
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('billing_frequency')
                    ->label('Billed')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('property')
                    ->relationship('unit.property', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('billing_frequency')->options(BillingFrequency::class),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->emptyStateHeading('No leases')
            ->emptyStateDescription('Let a vacant unit to create the first lease.');
    }

    public static function getRelations(): array
    {
        return [
            InvoicesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeases::route('/'),
            'create' => CreateLease::route('/create'),
            'view' => ViewLease::route('/{record}'),
            'edit' => EditLease::route('/{record}/edit'),
        ];
    }

    private static function applyTermLength(Get $get, Set $set): void
    {
        $months = $get('term_months');

        if ($months === null || $months === '' || blank($get('start_date'))) {
            return;
        }

        $set('end_date', (int) $months === 0 ? null : Carbon::parse($get('start_date'))->addMonthsNoOverflow((int) $months)->subDay()->toDateString());
    }
}
