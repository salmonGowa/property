<?php

namespace App\Filament\Resources\PurchaseOrders;

use App\Enums\PurchaseOrderStatus;
use App\Enums\UserRole;
use App\Enums\WorkOrderStatus;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Filament\Resources\SupplierBills\SupplierBillResource;
use App\Filament\Resources\Vendors\VendorResource;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\Property;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn as EntryColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use UnitEnum;

class PurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|UnitEnum|null $navigationGroup = 'Procurement & Payables';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'description', 'vendor.name'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['Supplier' => $record->vendor->name, 'Total' => 'KES '.Number::format((float) $record->total, 2), 'Status' => $record->status->getLabel()];
    }

    public static function getNavigationBadge(): ?string
    {
        $user = Auth::user();
        $count = $user ? self::awaitingApprovalBy(PurchaseOrder::query(), $user)->count() : 0;

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Awaiting your approval';
    }

    /**
     * Orders the user could approve now: submitted, raised by someone else, within their limit.
     */
    public static function awaitingApprovalBy(Builder $query, User $user): Builder
    {
        $isAdmin = $user->hasRole(UserRole::Admin);

        return $query
            ->where('status', PurchaseOrderStatus::PendingApproval)
            ->when(! $isAdmin, fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->whereNull('requested_by')->orWhere('requested_by', '!=', $user->id))
                ->when($user->purchase_approval_limit === null, fn (Builder $query) => $query->whereRaw('1 = 0'))
                ->when($user->purchase_approval_limit !== null, fn (Builder $query) => $query->where('total', '<=', $user->purchase_approval_limit)));
    }

    /**
     * Who could approve an order of this total, other than its requester.
     */
    public static function approversFor(float $total, ?int $requesterId): string
    {
        $approvers = User::query()
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query->where('role', UserRole::Admin)->orWhere('purchase_approval_limit', '>=', $total))
            ->when($requesterId, fn (Builder $query) => $query->whereKeyNot($requesterId))
            ->orderBy('name')
            ->get();

        if ($approvers->isEmpty()) {
            return 'Nobody else can approve this amount yet. An administrator can set approval limits under Administration → Users.';
        }

        return 'Can be approved by '.$approvers->take(4)->map(fn (User $user): string => $user->hasRole(UserRole::Admin) ? "{$user->name} (admin)" : "{$user->name} (up to KES ".Number::format((float) $user->purchase_approval_limit, 0).')')->implode(', ')
            .($approvers->count() > 4 ? ' and '.($approvers->count() - 4).' more' : '').'.';
    }

    public static function form(Schema $schema): Schema
    {
        $vendorIsVatRegistered = fn (Get $get): bool => (bool) Vendor::query()->find($get('vendor_id'))?->is_vat_registered;

        return $schema
            ->columns(3)
            ->components([
                Callout::make('Returned for changes')
                    ->description(fn (?PurchaseOrder $record): ?string => $record?->rejection_reason ? "{$record->rejecter?->name}: {$record->rejection_reason}" : null)
                    ->warning()
                    ->visible(fn (?PurchaseOrder $record): bool => filled($record?->rejection_reason))
                    ->columnSpanFull(),
                Section::make('Order')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        Select::make('vendor_id')
                            ->label('Supplier')
                            ->options(fn (): array => VendorResource::options())
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set) use ($vendorIsVatRegistered): void {
                                if (! $vendorIsVatRegistered($get)) {
                                    $set('lines', collect($get('lines') ?? [])->map(fn (array $line): array => [...$line, 'vat' => false])->all());
                                }
                            }),
                        Select::make('work_order_id')
                            ->label('For work order (optional)')
                            ->options(fn (): array => WorkOrder::query()->whereIn('status', WorkOrderStatus::unfinished())->with('property')->latest('reported_at')->get()
                                ->mapWithKeys(fn (WorkOrder $workOrder): array => [$workOrder->id => "{$workOrder->number} · {$workOrder->property->name} · {$workOrder->title}"])->all())
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(fn (?string $state, Set $set) => $state ? $set('property_id', WorkOrder::query()->find($state)?->property_id) : null),
                        Select::make('property_id')
                            ->label('Property (cost centre)')
                            ->options(fn (): array => Property::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->disabled(fn (Get $get): bool => filled($get('work_order_id')))
                            ->dehydrated()
                            ->helperText(fn (Get $get): ?string => filled($get('work_order_id')) ? 'Taken from the work order.' : null),
                        TextInput::make('description')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g. Repaint Block A stairwell'),
                    ]),
                Section::make('Dates')
                    ->columnSpan(1)
                    ->schema([
                        DatePicker::make('order_date')->required()->default(today()),
                        DatePicker::make('expected_date')->label('Needed by')->afterOrEqual('order_date'),
                    ]),
                Repeater::make('lines')
                    ->label('What is being ordered')
                    ->columnSpanFull()
                    ->minItems(1)
                    ->defaultItems(1)
                    ->addActionLabel('Add line')
                    ->table([
                        TableColumn::make('Charge to')->markAsRequired(),
                        TableColumn::make('Description')->markAsRequired(),
                        TableColumn::make('Qty')->markAsRequired()->width('110px'),
                        TableColumn::make('Unit price (KES, excl. VAT)')->markAsRequired(),
                        TableColumn::make('VAT '.config('ledger.vat_rate').'%')->width('90px'),
                    ])
                    ->schema([
                        Select::make('account_id')
                            ->options(fn (): array => SupplierBillResource::chargeableAccountOptions())
                            ->default(fn (): ?int => SupplierBillResource::defaultExpenseAccountId())
                            ->searchable()
                            ->required(),
                        TextInput::make('description')->required()->maxLength(255),
                        TextInput::make('quantity')->numeric()->minValue(0.01)->rule('decimal:0,2')->default(1)->required()->live(onBlur: true),
                        TextInput::make('unit_price')->numeric()->minValue(0.01)->rule('decimal:0,2')->required()->live(onBlur: true),
                        Toggle::make('vat')
                            ->live()
                            ->disabled(fn (Get $get): bool => ! (bool) Vendor::query()->find($get('../../vendor_id'))?->is_vat_registered),
                    ]),
                Text::make(function (Get $get): string {
                    [$net, $vat] = self::linesTotals($get('lines') ?? []);

                    return 'Subtotal KES '.Number::format($net, 2).'  ·  VAT KES '.Number::format($vat, 2).'  ·  Total KES '.Number::format($net + $vat, 2);
                })
                    ->weight(FontWeight::SemiBold)
                    ->columnSpanFull(),
                Text::make(function (Get $get, ?PurchaseOrder $record): string {
                    [$net, $vat] = self::linesTotals($get('lines') ?? []);

                    return $net + $vat > 0 ? self::approversFor($net + $vat, $record?->requested_by ?? Auth::id()) : 'Add lines to see who can approve this order.';
                })
                    ->color('gray')
                    ->columnSpanFull(),
                Textarea::make('notes')->label('Notes for the supplier')->rows(2)->columnSpanFull(),
            ]);
    }

    /**
     * @param  array<array{quantity?: mixed, unit_price?: mixed, vat?: mixed}>  $lines
     * @return array{0: float, 1: float}
     */
    private static function linesTotals(array $lines): array
    {
        $net = 0.0;
        $vat = 0.0;

        foreach ($lines as $line) {
            $amount = round((float) ($line['quantity'] ?? 0) * (float) ($line['unit_price'] ?? 0), 2);
            $net += $amount;
            $vat += ! empty($line['vat']) ? round($amount * (float) config('ledger.vat_rate') / 100, 2) : 0;
        }

        return [$net, $vat];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Callout::make('Returned for changes')
                    ->description(fn (PurchaseOrder $record): string => "{$record->rejecter?->name}: {$record->rejection_reason}")
                    ->warning()
                    ->visible(fn (PurchaseOrder $record): bool => $record->status === PurchaseOrderStatus::Draft && filled($record->rejection_reason))
                    ->columnSpanFull(),
                Callout::make('Awaiting approval')
                    ->description(fn (PurchaseOrder $record): string => self::approversFor((float) $record->total, $record->requested_by))
                    ->info()
                    ->visible(fn (PurchaseOrder $record): bool => $record->status === PurchaseOrderStatus::PendingApproval)
                    ->columnSpanFull(),
                Section::make()
                    ->columnSpan(2)
                    ->columns(3)
                    ->schema([
                        TextEntry::make('vendor.name')
                            ->label('Supplier')
                            ->weight('bold')
                            ->url(fn (PurchaseOrder $record): string => VendorResource::getUrl('view', ['record' => $record->vendor_id])),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('property.name')->label('Property')->placeholder('-'),
                        TextEntry::make('description')->columnSpanFull(),
                        TextEntry::make('workOrder.number')
                            ->label('Work order')
                            ->formatStateUsing(fn (PurchaseOrder $record): string => "{$record->workOrder->number} · {$record->workOrder->title}")
                            ->url(fn (PurchaseOrder $record): ?string => $record->work_order_id ? WorkOrderResource::getUrl('view', ['record' => $record->work_order_id]) : null)
                            ->placeholder('-'),
                        TextEntry::make('order_date')->date(),
                        TextEntry::make('expected_date')->label('Needed by')->date()->placeholder('-'),
                        TextEntry::make('requester.name')->label('Raised by')->placeholder('-'),
                        TextEntry::make('approver.name')
                            ->label('Approved by')
                            ->helperText(fn (PurchaseOrder $record): ?string => $record->approved_at?->format('j M Y H:i'))
                            ->placeholder('-'),
                        TextEntry::make('close_reason')
                            ->label(fn (PurchaseOrder $record): string => $record->status === PurchaseOrderStatus::Cancelled ? 'Cancelled because' : 'Closed because')
                            ->visible(fn (PurchaseOrder $record): bool => in_array($record->status, [PurchaseOrderStatus::Closed, PurchaseOrderStatus::Cancelled], true)),
                        TextEntry::make('notes')->label('Notes for the supplier')->placeholder('-')->columnSpanFull(),
                    ]),
                Section::make('Amounts')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('subtotal')->money('KES')->inlineLabel(),
                        TextEntry::make('vat_amount')->label('VAT')->money('KES')->inlineLabel(),
                        TextEntry::make('total')->money('KES')->inlineLabel()->weight('bold'),
                        TextEntry::make('billed')->label('Billed (excl. VAT)')->state(fn (PurchaseOrder $record): string => $record->billedSubtotal())->money('KES')->inlineLabel(),
                        TextEntry::make('remaining')
                            ->label('Left to bill')
                            ->state(fn (PurchaseOrder $record): string => $record->remainingSubtotal())
                            ->money('KES')
                            ->inlineLabel()
                            ->weight('bold')
                            ->visible(fn (PurchaseOrder $record): bool => $record->status === PurchaseOrderStatus::Approved),
                    ]),
                RepeatableEntry::make('lines')
                    ->columnSpanFull()
                    ->table([
                        EntryColumn::make('Charge to'),
                        EntryColumn::make('Description'),
                        EntryColumn::make('Qty'),
                        EntryColumn::make('Unit price'),
                        EntryColumn::make('Amount'),
                        EntryColumn::make('VAT'),
                    ])
                    ->schema([
                        TextEntry::make('account.name')->formatStateUsing(fn ($record): string => "{$record->account->code} {$record->account->name}"),
                        TextEntry::make('description'),
                        TextEntry::make('quantity')->numeric(2),
                        TextEntry::make('unit_price')->money('KES'),
                        TextEntry::make('amount')->money('KES'),
                        TextEntry::make('vat_amount')->money('KES'),
                    ]),
                RepeatableEntry::make('bills')
                    ->label('Supplier bills against this order')
                    ->columnSpanFull()
                    ->visible(fn (PurchaseOrder $record): bool => $record->bills()->exists())
                    ->table([
                        EntryColumn::make('Bill'),
                        EntryColumn::make('Supplier invoice'),
                        EntryColumn::make('Date'),
                        EntryColumn::make('Excl. VAT'),
                        EntryColumn::make('Status'),
                    ])
                    ->schema([
                        TextEntry::make('number')->url(fn ($record): string => SupplierBillResource::getUrl('view', ['record' => $record])),
                        TextEntry::make('vendor_invoice_number'),
                        TextEntry::make('bill_date')->date(),
                        TextEntry::make('subtotal')->money('KES'),
                        TextEntry::make('status')->badge(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['vendor', 'property', 'requester']))
            ->defaultSort('order_date', 'desc')
            ->columns([
                TextColumn::make('number')->searchable()->sortable()->weight('semibold'),
                TextColumn::make('vendor.name')->label('Supplier')->searchable()->hidden(fn ($livewire): bool => $livewire instanceof RelationManager),
                TextColumn::make('description')
                    ->searchable()
                    ->limit(40)
                    ->description(fn (PurchaseOrder $record): ?string => $record->property?->name),
                TextColumn::make('order_date')->date()->sortable(),
                TextColumn::make('requester.name')->label('Raised by')->toggleable(),
                TextColumn::make('total')->money('KES')->alignEnd()->sortable()->summarize(Sum::make()->money('KES')->label('Total')),
                TextColumn::make('status')->badge(),
            ])
            ->filters([
                SelectFilter::make('vendor')->label('Supplier')->relationship('vendor', 'name')->searchable()->preload(),
                SelectFilter::make('property')->relationship('property', 'name')->searchable()->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('No purchase orders')
            ->emptyStateDescription('Raise a purchase order before work starts so the cost is approved within someone\'s limit.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseOrders::route('/'),
            'create' => CreatePurchaseOrder::route('/create'),
            'view' => ViewPurchaseOrder::route('/{record}'),
            'edit' => EditPurchaseOrder::route('/{record}/edit'),
        ];
    }
}
