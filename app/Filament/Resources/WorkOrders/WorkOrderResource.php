<?php

namespace App\Filament\Resources\WorkOrders;

use App\Enums\InvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\UnitStatus;
use App\Enums\WorkOrderCategory;
use App\Enums\WorkOrderPriority;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Properties\PropertyResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\SupplierBills\SupplierBillResource;
use App\Filament\Resources\Tenants\TenantResource;
use App\Filament\Resources\Units\UnitResource;
use App\Filament\Resources\Vendors\VendorResource;
use App\Filament\Resources\WorkOrders\Pages\CreateWorkOrder;
use App\Filament\Resources\WorkOrders\Pages\EditWorkOrder;
use App\Filament\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Filament\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Models\Lease;
use App\Models\Property;
use App\Models\PurchaseOrder;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\Money;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn as EntryColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
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
use UnitEnum;

class WorkOrderResource extends Resource
{
    protected static ?string $model = WorkOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = 'Maintenance';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'title', 'property.name', 'unit.code'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['Job' => $record->title, 'Property' => $record->property->name, 'Status' => $record->status->getLabel()];
    }

    public static function getNavigationBadge(): ?string
    {
        $overdue = WorkOrder::query()->overdue()->count();

        return $overdue > 0 ? (string) $overdue : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Overdue work orders';
    }

    /**
     * When the job is due, in words: "3 days overdue", "Due today", "Due in 2 days".
     */
    public static function dueText(WorkOrder $workOrder): ?string
    {
        if (! $workOrder->status->isUnfinished()) {
            return null;
        }

        $days = (int) today()->diffInDays($workOrder->due_on, false);

        return match (true) {
            $days < 0 => abs($days).' '.str('day')->plural(abs($days)).' overdue',
            $days === 0 => 'Due today',
            default => "Due in {$days} ".str('day')->plural($days),
        };
    }

    public static function form(Schema $schema): Schema
    {
        $isCreating = fn (string $operation): bool => $operation === 'create';

        return $schema
            ->columns(3)
            ->components([
                Section::make('What and where')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        Select::make('property_id')
                            ->label('Property')
                            ->options(fn (): array => Property::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('unit_id', null))
                            ->disabled(fn (string $operation): bool => $operation !== 'create'),
                        Select::make('unit_id')
                            ->label('Unit (leave blank for common areas)')
                            ->options(fn (Get $get): array => Unit::query()->where('property_id', $get('property_id'))->orderBy('code')->get()
                                ->mapWithKeys(fn (Unit $unit): array => [$unit->id => "{$unit->code} · {$unit->status->getLabel()}"])->all())
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                $set('tenant_id', Lease::query()->active()->where('unit_id', $state)->value('tenant_id'));
                                $set('takes_unit_offline', false);
                            })
                            ->disabled(fn (string $operation): bool => $operation !== 'create'),
                        TextInput::make('title')
                            ->label('Problem')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g. Kitchen sink leaking under the cabinet')
                            ->columnSpanFull(),
                        Select::make('category')
                            ->options(WorkOrderCategory::class)
                            ->default(WorkOrderCategory::General->value)
                            ->required()
                            ->live(),
                        Select::make('tenant_id')
                            ->label('Reported by tenant')
                            ->options(fn (): array => Tenant::query()->where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn (Tenant $tenant): array => [$tenant->id => "{$tenant->name} ({$tenant->account_number})"])->all())
                            ->searchable()
                            ->helperText('Filled in from the unit\'s current lease.'),
                        Textarea::make('description')->label('Details')->rows(3)->columnSpanFull(),
                    ]),
                Section::make('Priority')
                    ->columnSpan(1)
                    ->schema([
                        Radio::make('priority')
                            ->hiddenLabel()
                            ->options(WorkOrderPriority::class)
                            ->default(WorkOrderPriority::Normal->value)
                            ->required(),
                        DatePicker::make('due_on')
                            ->label('Due')
                            ->visible(fn (string $operation): bool => $operation !== 'create')
                            ->required(),
                    ]),
                Section::make('Who will do it')
                    ->columnSpan(2)
                    ->columns(2)
                    ->visible($isCreating)
                    ->schema([
                        Select::make('vendor_id')
                            ->label('Supplier')
                            ->options(fn (Get $get): array => VendorResource::options($get('category') instanceof WorkOrderCategory ? $get('category')->value : $get('category')))
                            ->searchable()
                            ->placeholder('Assign later')
                            ->helperText('Suppliers that cover the category are listed first.'),
                        TextInput::make('estimated_cost')->numeric()->minValue(0)->rule('decimal:0,2')->prefix('KES'),
                    ]),
                Section::make('Options')
                    ->columnSpan(fn (string $operation): int => $operation === 'create' ? 1 : 2)
                    ->schema([
                        Toggle::make('tenant_chargeable')
                            ->label('Tenant caused the damage')
                            ->helperText('You can invoice the tenant for it once the work is done.'),
                        Toggle::make('takes_unit_offline')
                            ->label('Take the unit off the market')
                            ->helperText('Marks the vacant unit "Under Maintenance" until the work is completed.')
                            ->visible(fn (Get $get, string $operation): bool => $operation === 'create' && Unit::query()->find($get('unit_id'))?->status === UnitStatus::Vacant),
                        TextInput::make('estimated_cost')
                            ->numeric()
                            ->minValue(0)
                            ->rule('decimal:0,2')
                            ->prefix('KES')
                            ->visible(fn (string $operation): bool => $operation !== 'create'),
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
                        TextEntry::make('title')->label('Problem')->weight('bold')->columnSpan(2),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('property.name')
                            ->label('Property')
                            ->url(fn (WorkOrder $record): string => PropertyResource::getUrl('view', ['record' => $record->property_id])),
                        TextEntry::make('unit.code')
                            ->label('Unit')
                            ->url(fn (WorkOrder $record): ?string => $record->unit_id ? UnitResource::getUrl('view', ['record' => $record->unit_id]) : null)
                            ->placeholder('Common area'),
                        TextEntry::make('tenant.name')
                            ->label('Reported by')
                            ->url(fn (WorkOrder $record): ?string => $record->tenant_id ? TenantResource::getUrl('view', ['record' => $record->tenant_id]) : null)
                            ->helperText(fn (WorkOrder $record): ?string => $record->tenant?->phone)
                            ->placeholder('Staff'),
                        TextEntry::make('category'),
                        TextEntry::make('priority')->badge(),
                        TextEntry::make('due_on')
                            ->label('Due')
                            ->date()
                            ->color(fn (WorkOrder $record): ?string => $record->isOverdue() ? 'danger' : null)
                            ->helperText(fn (WorkOrder $record): ?string => self::dueText($record)),
                        TextEntry::make('description')->label('Details')->placeholder('-')->columnSpanFull(),
                        TextEntry::make('vendor.name')
                            ->label('Supplier')
                            ->url(fn (WorkOrder $record): ?string => $record->vendor_id ? VendorResource::getUrl('view', ['record' => $record->vendor_id]) : null)
                            ->helperText(fn (WorkOrder $record): ?string => $record->vendor?->phone)
                            ->placeholder('Not assigned'),
                        IconEntry::make('tenant_chargeable')->label('Tenant caused it')->boolean(),
                        TextEntry::make('schedule.title')->label('Planned maintenance')->placeholder('-'),
                        TextEntry::make('resolution')->label('Work done')->visible(fn (WorkOrder $record): bool => filled($record->resolution))->columnSpanFull(),
                        TextEntry::make('cancel_reason')->label('Cancelled because')->visible(fn (WorkOrder $record): bool => filled($record->cancel_reason))->columnSpanFull(),
                    ]),
                Section::make('Timeline')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('reported_at')->label('Reported')->dateTime('j M Y H:i')->inlineLabel(),
                        TextEntry::make('assigned_at')->label('Assigned')->dateTime('j M Y H:i')->inlineLabel()->placeholder('-'),
                        TextEntry::make('started_at')->label('Started')->dateTime('j M Y H:i')->inlineLabel()->placeholder('-'),
                        TextEntry::make('completed_at')
                            ->label('Completed')
                            ->dateTime('j M Y H:i')
                            ->inlineLabel()
                            ->placeholder('-')
                            ->helperText(fn (WorkOrder $record): ?string => $record->completed_at ? ($record->completed_at->startOfDay()->lte($record->due_on) ? 'On time' : 'Late by '.$record->due_on->diffInDays($record->completed_at->startOfDay()).' day(s)') : null),
                    ]),
                Section::make('Cost')
                    ->columnSpanFull()
                    ->columns(4)
                    ->schema([
                        TextEntry::make('estimated_cost')->label('Estimate')->money('KES')->placeholder('-'),
                        TextEntry::make('committed')
                            ->label('Ordered, not yet billed')
                            ->state(fn (WorkOrder $record): string => Money::sum($record->purchaseOrders()->where('status', PurchaseOrderStatus::Approved)->get()->map(fn (PurchaseOrder $order): string => $order->remainingSubtotal())))
                            ->money('KES'),
                        TextEntry::make('actual')
                            ->label('Billed (excl. VAT)')
                            ->state(fn (WorkOrder $record): string => $record->actualCost())
                            ->money('KES')
                            ->weight('bold')
                            ->color(fn (WorkOrder $record, string $state): ?string => $record->estimated_cost !== null && bccomp($state, (string) $record->estimated_cost, 2) > 0 ? 'danger' : null),
                        TextEntry::make('rechargeInvoice.number')
                            ->label('Recharged to tenant')
                            ->formatStateUsing(fn (WorkOrder $record): string => "{$record->rechargeInvoice->number} · KES ".number_format((float) $record->rechargeInvoice->total, 2).($record->rechargeInvoice->status === InvoiceStatus::Void ? ' (void)' : ''))
                            ->url(fn (WorkOrder $record): ?string => $record->recharge_invoice_id ? InvoiceResource::getUrl('view', ['record' => $record->recharge_invoice_id]) : null)
                            ->placeholder('-'),
                    ]),
                RepeatableEntry::make('purchaseOrders')
                    ->label('Purchase orders')
                    ->columnSpanFull()
                    ->visible(fn (WorkOrder $record): bool => $record->purchaseOrders()->exists())
                    ->table([EntryColumn::make('Order'), EntryColumn::make('Supplier'), EntryColumn::make('Description'), EntryColumn::make('Total'), EntryColumn::make('Status')])
                    ->schema([
                        TextEntry::make('number')->url(fn ($record): string => PurchaseOrderResource::getUrl('view', ['record' => $record])),
                        TextEntry::make('vendor.name'),
                        TextEntry::make('description'),
                        TextEntry::make('total')->money('KES'),
                        TextEntry::make('status')->badge(),
                    ]),
                RepeatableEntry::make('bills')
                    ->label('Supplier bills')
                    ->columnSpanFull()
                    ->visible(fn (WorkOrder $record): bool => $record->bills()->exists())
                    ->table([EntryColumn::make('Bill'), EntryColumn::make('Supplier'), EntryColumn::make('Invoice'), EntryColumn::make('Excl. VAT'), EntryColumn::make('Status')])
                    ->schema([
                        TextEntry::make('number')->url(fn ($record): string => SupplierBillResource::getUrl('view', ['record' => $record])),
                        TextEntry::make('vendor.name'),
                        TextEntry::make('vendor_invoice_number'),
                        TextEntry::make('subtotal')->money('KES'),
                        TextEntry::make('status')->badge(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['property', 'unit', 'vendor']))
            ->defaultSort('due_on')
            ->columns([
                TextColumn::make('number')->searchable()->sortable()->weight('semibold')->toggleable(),
                TextColumn::make('title')
                    ->label('Problem')
                    ->searchable()
                    ->limit(45)
                    ->tooltip(fn (WorkOrder $record): string => $record->title)
                    ->description(fn (WorkOrder $record): string => $record->property->name.($record->unit ? " / {$record->unit->code}" : ' / common area')),
                TextColumn::make('category')->toggleable(),
                TextColumn::make('priority')->badge()->sortable(),
                TextColumn::make('vendor.name')->label('Supplier')->placeholder('Not assigned')->hidden(fn ($livewire): bool => $livewire instanceof RelationManager && $livewire->getOwnerRecord() instanceof Vendor),
                TextColumn::make('due_on')
                    ->label('Due')
                    ->date()
                    ->sortable()
                    ->color(fn (WorkOrder $record): ?string => $record->isOverdue() ? 'danger' : null)
                    ->description(fn (WorkOrder $record): ?string => self::dueText($record)),
                TextColumn::make('status')->badge(),
            ])
            ->filters([
                SelectFilter::make('property')->relationship('property', 'name')->searchable()->preload(),
                SelectFilter::make('category')->options(WorkOrderCategory::class),
                SelectFilter::make('priority')->options(WorkOrderPriority::class),
                SelectFilter::make('vendor')->label('Supplier')->relationship('vendor', 'name')->searchable()->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('No work orders')
            ->emptyStateDescription('Log repairs as tenants report them; planned maintenance raises work orders automatically.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkOrders::route('/'),
            'create' => CreateWorkOrder::route('/create'),
            'view' => ViewWorkOrder::route('/{record}'),
            'edit' => EditWorkOrder::route('/{record}/edit'),
        ];
    }
}
