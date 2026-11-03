<?php

namespace App\Filament\Resources\SupplierBills;

use App\Enums\AccountType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\WorkOrderStatus;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\SupplierBills\Pages\CreateSupplierBill;
use App\Filament\Resources\SupplierBills\Pages\ListSupplierBills;
use App\Filament\Resources\SupplierBills\Pages\ViewSupplierBill;
use App\Filament\Resources\SupplierPayments\SupplierPaymentResource;
use App\Filament\Resources\Vendors\VendorResource;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\Account;
use App\Models\Property;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SupplierBill;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\Ledger\InvalidJournalEntry;
use App\Services\Procurement\SupplierBillService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn as EntryColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use UnitEnum;

class SupplierBillResource extends Resource
{
    protected static ?string $model = SupplierBill::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Procurement & Payables';

    protected static ?string $navigationLabel = 'Supplier Bills';

    protected static ?string $modelLabel = 'supplier bill';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'vendor_invoice_number', 'vendor.name'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['Supplier' => $record->vendor->name, 'Invoice' => $record->vendor_invoice_number, 'Status' => $record->status->getLabel()];
    }

    public static function getNavigationBadge(): ?string
    {
        $overdue = SupplierBill::query()->overdue()->count();

        return $overdue > 0 ? (string) $overdue : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Overdue supplier bills';
    }

    /**
     * Expense and asset accounts a purchase can be charged to, grouped by type.
     *
     * @return array<string, array<int, string>>
     */
    public static function chargeableAccountOptions(): array
    {
        return Account::query()->postable()->whereIn('type', SupplierBillService::chargeableAccountTypes())->orderBy('code')->get()
            ->groupBy(fn (Account $account): string => $account->type === AccountType::Expense ? 'Expenses' : 'Assets (stores, equipment)')
            ->sortKeysDesc()
            ->map(fn ($accounts) => $accounts->mapWithKeys(fn (Account $account): array => [$account->id => "{$account->code} {$account->name}"])->all())
            ->all();
    }

    public static function defaultExpenseAccountId(): ?int
    {
        return Account::query()->where('code', config('ledger.accounts.repairs'))->value('id');
    }

    public static function form(Schema $schema): Schema
    {
        $vendor = fn (Get $get): ?Vendor => Vendor::query()->find($get('vendor_id'));

        return $schema
            ->columns(3)
            ->components([
                Section::make('Supplier invoice')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        Select::make('vendor_id')
                            ->label('Supplier')
                            ->options(fn (): array => VendorResource::options())
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set) use ($vendor): void {
                                $set('purchase_order_id', null);
                                self::setDueDate($get, $set, $vendor($get));
                            }),
                        TextInput::make('vendor_invoice_number')
                            ->label('Supplier\'s invoice number')
                            ->required()
                            ->maxLength(60),
                        Select::make('purchase_order_id')
                            ->label('Against purchase order')
                            ->options(fn (Get $get): array => PurchaseOrder::query()->where('vendor_id', $get('vendor_id'))->where('status', PurchaseOrderStatus::Approved)->orderBy('number')->get()
                                ->mapWithKeys(fn (PurchaseOrder $order): array => [$order->id => "{$order->number} · {$order->description} · KES ".Number::format((float) $order->remainingSubtotal(), 2).' left'])->all())
                            ->placeholder('No purchase order')
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                if ($order = PurchaseOrder::query()->find($state)) {
                                    foreach (self::orderState($order) as $key => $value) {
                                        $set($key, $value);
                                    }
                                }
                            })
                            ->helperText('Billing against an approved order checks the amount is within what was approved.'),
                        Select::make('work_order_id')
                            ->label('For work order')
                            ->options(fn (): array => WorkOrder::query()->where('status', '!=', WorkOrderStatus::Cancelled)->with('property')->latest('reported_at')->limit(200)->get()
                                ->mapWithKeys(fn (WorkOrder $workOrder): array => [$workOrder->id => "{$workOrder->number} · {$workOrder->property->name} · {$workOrder->title}"])->all())
                            ->searchable()
                            ->disabled(fn (Get $get): bool => filled($get('purchase_order_id')))
                            ->dehydrated(),
                        Select::make('property_id')
                            ->label('Property (cost centre)')
                            ->options(fn (): array => Property::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->disabled(fn (Get $get): bool => filled($get('purchase_order_id')) || filled($get('work_order_id')))
                            ->dehydrated(),
                        TextInput::make('description')->required()->maxLength(255),
                    ]),
                Section::make('Dates')
                    ->columnSpan(1)
                    ->schema([
                        DatePicker::make('bill_date')
                            ->label('Invoice date')
                            ->default(today())
                            ->maxDate(today())
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::setDueDate($get, $set, $vendor($get))),
                        DatePicker::make('due_date')
                            ->required()
                            ->afterOrEqual('bill_date')
                            ->helperText(fn (Get $get): ?string => ($v = $vendor($get)) ? "{$v->payment_terms_days}-day terms" : null),
                    ]),
                Repeater::make('lines')
                    ->label('Charges')
                    ->columnSpanFull()
                    ->minItems(1)
                    ->defaultItems(1)
                    ->addActionLabel('Add line')
                    ->table([
                        TableColumn::make('Charge to')->markAsRequired(),
                        TableColumn::make('Description')->markAsRequired(),
                        TableColumn::make('Amount (KES, excl. VAT)')->markAsRequired(),
                        TableColumn::make('VAT '.config('ledger.vat_rate').'%')->width('90px'),
                    ])
                    ->schema([
                        Select::make('account_id')
                            ->options(fn (): array => self::chargeableAccountOptions())
                            ->default(fn (): ?int => self::defaultExpenseAccountId())
                            ->searchable()
                            ->required(),
                        TextInput::make('description')->required()->maxLength(255),
                        TextInput::make('amount')->numeric()->minValue(0.01)->rule('decimal:0,2')->required()->live(onBlur: true),
                        Toggle::make('vat')
                            ->live()
                            ->disabled(fn (Get $get): bool => ! (bool) Vendor::query()->find($get('../../vendor_id'))?->is_vat_registered),
                    ]),
                Text::make(function (Get $get) use ($vendor): string {
                    $lines = collect($get('lines') ?? []);
                    $net = $lines->sum(fn (array $line): float => (float) ($line['amount'] ?? 0));
                    $vat = $lines->sum(fn (array $line): float => ! empty($line['vat']) ? round((float) ($line['amount'] ?? 0) * (float) config('ledger.vat_rate') / 100, 2) : 0);
                    $whtRate = (float) ($vendor($get)?->withholding_tax_type->rate() ?? 0);
                    $wht = round($net * $whtRate / 100, 2);

                    return 'Subtotal KES '.Number::format($net, 2).'  ·  VAT KES '.Number::format($vat, 2).'  ·  Total KES '.Number::format($net + $vat, 2)
                        .($wht > 0 ? '  ·  Withholding tax '.Number::format($whtRate, 0).'% KES '.Number::format($wht, 2) : '')
                        .'  ·  Pay supplier KES '.Number::format($net + $vat - $wht, 2);
                })
                    ->weight(FontWeight::SemiBold)
                    ->columnSpanFull(),
            ]);
    }

    private static function setDueDate(Get $get, Set $set, ?Vendor $vendor): void
    {
        if ($vendor && $get('bill_date')) {
            $set('due_date', Carbon::parse($get('bill_date'))->addDays($vendor->payment_terms_days)->toDateString());
        }
    }

    /**
     * Form state copied from an order: its lines (scaled down to what is left to bill) and cost centre.
     *
     * @return array<string, mixed>
     */
    public static function orderState(PurchaseOrder $order): array
    {
        $remaining = $order->remainingSubtotal();
        $ratio = bccomp((string) $order->subtotal, '0', 2) > 0 ? bcdiv($remaining, (string) $order->subtotal, 10) : '0';

        return [
            'work_order_id' => $order->work_order_id,
            'property_id' => $order->property_id,
            'description' => $order->description,
            'lines' => $order->lines->mapWithKeys(fn (PurchaseOrderLine $line): array => [(string) str()->uuid() => [
                'account_id' => $line->account_id,
                'description' => $line->description,
                'amount' => bcmul((string) $line->amount, $ratio, 2),
                'vat' => bccomp((string) $line->vat_amount, '0', 2) > 0,
            ]])->all(),
        ];
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
                        TextEntry::make('vendor.name')
                            ->label('Supplier')
                            ->weight('bold')
                            ->url(fn (SupplierBill $record): string => VendorResource::getUrl('view', ['record' => $record->vendor_id])),
                        TextEntry::make('vendor_invoice_number')->label('Supplier invoice')->copyable(),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('description')->columnSpanFull(),
                        TextEntry::make('bill_date')->label('Invoice date')->date(),
                        TextEntry::make('due_date')
                            ->date()
                            ->color(fn (SupplierBill $record): ?string => $record->isOverdue() ? 'danger' : null)
                            ->helperText(fn (SupplierBill $record): ?string => $record->isOverdue() ? $record->due_date->diffInDays(today()).' days overdue' : null),
                        TextEntry::make('property.name')->label('Property')->placeholder('-'),
                        TextEntry::make('purchaseOrder.number')
                            ->label('Purchase order')
                            ->url(fn (SupplierBill $record): ?string => $record->purchase_order_id ? PurchaseOrderResource::getUrl('view', ['record' => $record->purchase_order_id]) : null)
                            ->placeholder('None'),
                        TextEntry::make('workOrder.number')
                            ->label('Work order')
                            ->url(fn (SupplierBill $record): ?string => $record->work_order_id ? WorkOrderResource::getUrl('view', ['record' => $record->work_order_id]) : null)
                            ->placeholder('-'),
                        TextEntry::make('creator.name')->label('Entered by')->placeholder('-'),
                    ]),
                Section::make('Amounts')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('subtotal')->money('KES')->inlineLabel(),
                        TextEntry::make('vat_amount')->label('VAT input')->money('KES')->inlineLabel(),
                        TextEntry::make('total')->money('KES')->inlineLabel()->weight('bold'),
                        TextEntry::make('withholding_tax_amount')
                            ->label(fn (SupplierBill $record): string => 'Withholding tax '.rtrim(rtrim((string) $record->withholding_tax_rate, '0'), '.').'%')
                            ->money('KES')
                            ->inlineLabel()
                            ->helperText('Paid to KRA, not the supplier')
                            ->visible(fn (SupplierBill $record): bool => bccomp((string) $record->withholding_tax_amount, '0', 2) > 0),
                        TextEntry::make('payable_amount')->label('Payable to supplier')->money('KES')->inlineLabel(),
                        TextEntry::make('amount_paid')->label('Paid')->money('KES')->inlineLabel(),
                        TextEntry::make('balance_due')
                            ->label('Balance due')
                            ->state(fn (SupplierBill $record): string => $record->balanceDue())
                            ->money('KES')
                            ->inlineLabel()
                            ->size('lg')
                            ->weight('bold')
                            ->color(fn (SupplierBill $record): string => bccomp($record->balanceDue(), '0', 2) > 0 ? 'warning' : 'success'),
                    ]),
                RepeatableEntry::make('lines')
                    ->label('Charges')
                    ->columnSpanFull()
                    ->table([EntryColumn::make('Charged to'), EntryColumn::make('Description'), EntryColumn::make('Amount'), EntryColumn::make('VAT'), EntryColumn::make('Total')])
                    ->schema([
                        TextEntry::make('account.name')->formatStateUsing(fn ($record): string => "{$record->account->code} {$record->account->name}"),
                        TextEntry::make('description'),
                        TextEntry::make('amount')->money('KES'),
                        TextEntry::make('vat_amount')->money('KES'),
                        TextEntry::make('line_total')->state(fn ($record): string => $record->total())->money('KES'),
                    ]),
                RepeatableEntry::make('allocations')
                    ->label('Payments')
                    ->columnSpanFull()
                    ->visible(fn (SupplierBill $record): bool => $record->allocations()->exists())
                    ->state(fn (SupplierBill $record) => $record->allocations()->with('payment')->get())
                    ->table([EntryColumn::make('Voucher'), EntryColumn::make('Paid'), EntryColumn::make('Method'), EntryColumn::make('Reference'), EntryColumn::make('Applied')])
                    ->schema([
                        TextEntry::make('payment.number')->url(fn ($record): string => SupplierPaymentResource::getUrl('view', ['record' => $record->supplier_payment_id])),
                        TextEntry::make('payment.paid_on')->date(),
                        TextEntry::make('payment.method'),
                        TextEntry::make('payment.reference')->placeholder('-'),
                        TextEntry::make('amount')->money('KES'),
                    ]),
                Section::make('Accounting')
                    ->columnSpanFull()
                    ->collapsed()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('journalEntry.number')
                            ->label('Posted as')
                            ->url(fn (SupplierBill $record): ?string => $record->journal_entry_id ? JournalEntryResource::getUrl('view', ['record' => $record->journal_entry_id]) : null),
                        TextEntry::make('voided_at')->dateTime()->placeholder('-'),
                        TextEntry::make('void_reason')->placeholder('-'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['vendor', 'property']))
            ->defaultSort('due_date')
            ->columns([
                TextColumn::make('number')->searchable()->sortable()->weight('semibold')->description(fn (SupplierBill $record): string => "Inv {$record->vendor_invoice_number}"),
                TextColumn::make('vendor.name')->label('Supplier')->searchable()->hidden(fn ($livewire): bool => $livewire instanceof RelationManager),
                TextColumn::make('description')
                    ->searchable()
                    ->limit(40)
                    ->description(fn (SupplierBill $record): ?string => $record->property?->name),
                TextColumn::make('bill_date')->label('Invoice date')->date()->sortable()->toggleable(),
                TextColumn::make('due_date')
                    ->date()
                    ->sortable()
                    ->color(fn (SupplierBill $record): ?string => $record->isOverdue() ? 'danger' : null)
                    ->icon(fn (SupplierBill $record): ?Heroicon => $record->isOverdue() ? Heroicon::OutlinedClock : null),
                TextColumn::make('payable_amount')->label('Payable')->money('KES')->alignEnd()->sortable()->summarize(Sum::make()->money('KES')->label('Total')),
                TextColumn::make('balance_due')
                    ->label('Balance')
                    ->state(fn (SupplierBill $record): string => $record->balanceDue())
                    ->money('KES')
                    ->alignEnd()
                    ->color(fn (string $state): ?string => bccomp($state, '0', 2) > 0 ? 'warning' : 'gray'),
                TextColumn::make('status')->badge(),
            ])
            ->filters([
                SelectFilter::make('vendor')->label('Supplier')->relationship('vendor', 'name')->searchable()->preload(),
                SelectFilter::make('property')->relationship('property', 'name')->searchable()->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('No supplier bills')
            ->emptyStateDescription('Enter supplier invoices here as they arrive; they are posted to Accounts Payable.');
    }

    public static function voidAction(): Action
    {
        return Action::make('void')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('void')
            ->modalDescription('The bill stays on record marked Void and its ledger posting is reversed. Any purchase order it was billed against can be billed again.')
            ->schema([TextInput::make('reason')->required()->maxLength(200)])
            ->action(function (array $data, SupplierBill $record, SupplierBillService $bills): void {
                try {
                    $bills->void($record, $data['reason'], Auth::user());
                } catch (InvalidJournalEntry $exception) {
                    Notification::make()->title('Not voided')->body($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title("{$record->number} voided")->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplierBills::route('/'),
            'create' => CreateSupplierBill::route('/create'),
            'view' => ViewSupplierBill::route('/{record}'),
        ];
    }
}
