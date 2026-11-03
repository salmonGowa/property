<?php

namespace App\Filament\Resources\Invoices;

use App\Enums\ChargeType;
use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Resources\Leases\LeaseResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Tenants\TenantResource;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Tenant;
use App\Services\Billing\InvoiceService;
use App\Services\Ledger\InvalidJournalEntry;
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
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use UnitEnum;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Billing & Collections';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'tenant.name', 'description'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['Tenant' => $record->tenant->name, 'Total' => 'KES '.Number::format((float) $record->total, 2), 'Status' => $record->status->getLabel()];
    }

    public static function getNavigationBadge(): ?string
    {
        $overdue = Invoice::query()->overdue()->count();

        return $overdue > 0 ? (string) $overdue : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Overdue invoices';
    }

    /**
     * Form for raising a one-off invoice (utilities, repairs recharged, penalties...).
     * Rent and service charge are invoiced automatically by the billing run.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Bill to')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        Select::make('tenant_id')
                            ->label('Tenant')
                            ->options(fn (): array => Tenant::query()->where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn (Tenant $tenant): array => [$tenant->id => "{$tenant->name} ({$tenant->account_number})"])->all())
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('lease_id', null)),
                        Select::make('lease_id')
                            ->label('Lease (optional)')
                            ->options(fn (Get $get): array => Lease::query()->where('tenant_id', $get('tenant_id'))->active()->with('unit.property')->get()
                                ->mapWithKeys(fn (Lease $lease): array => [$lease->id => "{$lease->reference} - {$lease->unit->fullCode()}"])->all())
                            ->helperText('Links the charge to a unit for reporting.'),
                        TextInput::make('description')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g. Water recharge September 2026')
                            ->columnSpanFull(),
                    ]),
                Section::make('Dates')
                    ->columnSpan(1)
                    ->schema([
                        DatePicker::make('issue_date')->required()->default(today()),
                        DatePicker::make('due_date')->required()->default(today()->addDays(7))->afterOrEqual('issue_date'),
                    ]),
                Repeater::make('lines')
                    ->label('Charges')
                    ->columnSpanFull()
                    ->minItems(1)
                    ->defaultItems(1)
                    ->addActionLabel('Add charge')
                    ->table([
                        TableColumn::make('Type')->markAsRequired(),
                        TableColumn::make('Description')->markAsRequired(),
                        TableColumn::make('Amount (KES, excl. VAT)')->markAsRequired(),
                        TableColumn::make('VAT '.config('ledger.vat_rate').'%'),
                    ])
                    ->schema([
                        Select::make('charge_type')
                            ->options(collect(ChargeType::cases())->reject(fn (ChargeType $type): bool => $type === ChargeType::Deposit)->mapWithKeys(fn (ChargeType $type): array => [$type->value => $type->getLabel()])->all())
                            ->default(ChargeType::Utility->value)
                            ->required(),
                        TextInput::make('description')->required()->maxLength(255),
                        TextInput::make('amount')->numeric()->minValue(0.01)->rule('decimal:0,2')->required()->live(onBlur: true),
                        Toggle::make('vat')->live(),
                    ]),
                Text::make(function (Get $get): string {
                    $lines = collect($get('lines') ?? []);
                    $net = $lines->sum(fn (array $line): float => (float) ($line['amount'] ?? 0));
                    $vat = $lines->sum(fn (array $line): float => ! empty($line['vat']) ? (float) ($line['amount'] ?? 0) * (float) config('ledger.vat_rate') / 100 : 0);

                    return 'Subtotal KES '.Number::format($net, 2).'  ·  VAT KES '.Number::format($vat, 2).'  ·  Total KES '.Number::format($net + $vat, 2);
                })
                    ->weight(FontWeight::SemiBold)
                    ->columnSpanFull(),
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
                        TextEntry::make('tenant.name')
                            ->label('Billed to')
                            ->weight('bold')
                            ->url(fn (Invoice $record): string => TenantResource::getUrl('view', ['record' => $record->tenant_id])),
                        TextEntry::make('lease.reference')
                            ->label('Lease')
                            ->formatStateUsing(fn (Invoice $record): string => "{$record->lease->reference} - {$record->lease->unit->fullCode()}")
                            ->url(fn (Invoice $record): ?string => $record->lease_id ? LeaseResource::getUrl('view', ['record' => $record->lease_id]) : null)
                            ->placeholder('-'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('description')->columnSpanFull(),
                        TextEntry::make('issue_date')->date(),
                        TextEntry::make('due_date')
                            ->date()
                            ->color(fn (Invoice $record): ?string => $record->isOverdue() ? 'danger' : null)
                            ->helperText(fn (Invoice $record): ?string => $record->isOverdue() ? $record->due_date->diffInDays(today()).' days overdue' : null),
                        TextEntry::make('period')
                            ->state(fn (Invoice $record): ?string => $record->period_start ? $record->period_start->format('j M Y').' - '.$record->period_end->format('j M Y') : null)
                            ->placeholder('-'),
                    ]),
                Section::make('Amounts')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('subtotal')->money('KES')->inlineLabel(),
                        TextEntry::make('vat_amount')->label('VAT')->money('KES')->inlineLabel(),
                        TextEntry::make('total')->money('KES')->inlineLabel()->weight('bold'),
                        TextEntry::make('amount_paid')
                            ->label('Paid')
                            ->state(fn (Invoice $record): string => bcsub((string) $record->amount_paid, $record->amountCredited(), 2))
                            ->money('KES')
                            ->inlineLabel(),
                        TextEntry::make('credited')
                            ->state(fn (Invoice $record): string => $record->amountCredited())
                            ->money('KES')
                            ->inlineLabel()
                            ->visible(fn (Invoice $record): bool => bccomp($record->amountCredited(), '0', 2) > 0),
                        TextEntry::make('balance_due')
                            ->label('Balance due')
                            ->state(fn (Invoice $record): string => $record->balanceDue())
                            ->money('KES')
                            ->inlineLabel()
                            ->size('lg')
                            ->weight('bold')
                            ->color(fn (Invoice $record): string => bccomp($record->balanceDue(), '0', 2) > 0 ? 'danger' : 'success'),
                    ]),
                RepeatableEntry::make('lines')
                    ->label('Charges')
                    ->columnSpanFull()
                    ->table([
                        EntryColumn::make('Type'),
                        EntryColumn::make('Description'),
                        EntryColumn::make('Amount'),
                        EntryColumn::make('VAT'),
                        EntryColumn::make('Total'),
                    ])
                    ->schema([
                        TextEntry::make('charge_type'),
                        TextEntry::make('description'),
                        TextEntry::make('amount')->money('KES'),
                        TextEntry::make('vat_amount')->money('KES'),
                        TextEntry::make('line_total')->state(fn ($record): string => $record->total())->money('KES'),
                    ]),
                RepeatableEntry::make('allocations')
                    ->label('Payments applied')
                    ->columnSpanFull()
                    ->visible(fn (Invoice $record): bool => $record->allocations()->whereNotNull('payment_id')->exists())
                    ->state(fn (Invoice $record) => $record->allocations()->whereNotNull('payment_id')->with('payment')->get())
                    ->table([
                        EntryColumn::make('Receipt'),
                        EntryColumn::make('Received'),
                        EntryColumn::make('Method'),
                        EntryColumn::make('Applied'),
                    ])
                    ->schema([
                        TextEntry::make('payment.number')->url(fn ($record): string => PaymentResource::getUrl('view', ['record' => $record->payment_id])),
                        TextEntry::make('payment.received_on')->date(),
                        TextEntry::make('payment.method'),
                        TextEntry::make('amount')->money('KES'),
                    ]),
                RepeatableEntry::make('creditNotes')
                    ->label('Credit notes')
                    ->columnSpanFull()
                    ->visible(fn (Invoice $record): bool => $record->creditNotes()->exists())
                    ->table([
                        EntryColumn::make('Credit note'),
                        EntryColumn::make('Type'),
                        EntryColumn::make('Reason'),
                        EntryColumn::make('Amount'),
                    ])
                    ->schema([
                        TextEntry::make('number')->url(fn ($record): string => CreditNoteResource::getUrl('view', ['record' => $record])),
                        TextEntry::make('type')->badge(),
                        TextEntry::make('reason'),
                        TextEntry::make('amount')->money('KES'),
                    ]),
                RepeatableEntry::make('penaltyCharges')
                    ->label('Late payment penalties raised on this invoice')
                    ->columnSpanFull()
                    ->visible(fn (Invoice $record): bool => $record->penaltyCharges()->exists())
                    ->table([
                        EntryColumn::make('Assessed'),
                        EntryColumn::make('Rule'),
                        EntryColumn::make('Overdue then'),
                        EntryColumn::make('Penalty'),
                        EntryColumn::make('Penalty invoice'),
                    ])
                    ->schema([
                        TextEntry::make('assessed_on')->date(),
                        TextEntry::make('rule.name'),
                        TextEntry::make('basis')->money('KES'),
                        TextEntry::make('amount')->money('KES'),
                        TextEntry::make('penaltyInvoice.number')->url(fn ($record): string => self::getUrl('view', ['record' => $record->penalty_invoice_id])),
                    ]),
                Section::make('Accounting')
                    ->columnSpanFull()
                    ->collapsed()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('journalEntry.number')
                            ->label('Posted as')
                            ->url(fn (Invoice $record): ?string => $record->journal_entry_id ? JournalEntryResource::getUrl('view', ['record' => $record->journal_entry_id]) : null),
                        TextEntry::make('voided_at')->dateTime()->placeholder('-'),
                        TextEntry::make('void_reason')->placeholder('-'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['tenant', 'lease.unit.property']))
            ->defaultSort('issue_date', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),
                TextColumn::make('tenant.name')
                    ->searchable()
                    ->hidden(fn ($livewire): bool => $livewire instanceof RelationManager),
                TextColumn::make('description')
                    ->searchable()
                    ->limit(45)
                    ->tooltip(fn (Invoice $record): string => $record->description),
                TextColumn::make('issue_date')->date()->sortable(),
                TextColumn::make('due_date')
                    ->date()
                    ->sortable()
                    ->color(fn (Invoice $record): ?string => $record->isOverdue() ? 'danger' : null)
                    ->icon(fn (Invoice $record): ?Heroicon => $record->isOverdue() ? Heroicon::OutlinedClock : null),
                TextColumn::make('total')
                    ->money('KES')
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Sum::make()->money('KES')->label('Total')),
                TextColumn::make('balance_due')
                    ->label('Balance')
                    ->state(fn (Invoice $record): string => $record->balanceDue())
                    ->money('KES')
                    ->alignEnd()
                    ->color(fn (string $state): ?string => bccomp($state, '0', 2) > 0 ? 'danger' : 'gray'),
                TextColumn::make('status')->badge(),
            ])
            ->filters([
                SelectFilter::make('property')
                    ->relationship('lease.unit.property', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('issued')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('issue_date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('issue_date', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('No invoices')
            ->emptyStateDescription('Invoices are created when leases are activated and by the daily billing run.');
    }

    /**
     * Cancel an unpaid invoice by reversing its ledger posting.
     */
    public static function voidAction(): Action
    {
        return Action::make('void')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('void')
            ->modalDescription('The invoice stays on record marked Void and its ledger posting is reversed. Lease periods can then be billed again.')
            ->schema([
                TextInput::make('reason')->required()->maxLength(200),
            ])
            ->action(function (array $data, Invoice $record, InvoiceService $invoices): void {
                try {
                    $invoices->void($record, $data['reason'], Auth::user());
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
            'index' => ListInvoices::route('/'),
            'create' => CreateInvoice::route('/create'),
            'view' => ViewInvoice::route('/{record}'),
        ];
    }
}
