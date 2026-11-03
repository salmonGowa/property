<?php

namespace App\Filament\Resources\SupplierPayments;

use App\Enums\PaymentMethod;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Resources\SupplierBills\SupplierBillResource;
use App\Filament\Resources\SupplierPayments\Pages\ListSupplierPayments;
use App\Filament\Resources\SupplierPayments\Pages\ViewSupplierPayment;
use App\Filament\Resources\Vendors\VendorResource;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Models\Vendor;
use App\Services\Ledger\InvalidJournalEntry;
use App\Services\Procurement\ProcurementActionRefused;
use App\Services\Procurement\SupplierPaymentService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
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
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use UnitEnum;

class SupplierPaymentResource extends Resource
{
    protected static ?string $model = SupplierPayment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Procurement & Payables';

    protected static ?string $navigationLabel = 'Supplier Payments';

    protected static ?string $modelLabel = 'supplier payment';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'number';

    /**
     * "Pay supplier" modal, usable from the payments list, a supplier or a bill.
     */
    public static function payAction(?Vendor $vendor = null, ?SupplierBill $bill = null): Action
    {
        $vendorId = fn (Get $get): ?int => $vendor?->id ?? ($get('vendor_id') ? (int) $get('vendor_id') : null);
        $openBills = fn (?int $id): Collection => SupplierBill::query()->where('vendor_id', $id)->outstanding()->orderBy('due_date')->orderBy('id')->get();
        $selectedTotal = fn (?int $id, array $billIds): string => bcadd((string) $openBills($id)->whereIn('id', array_map('intval', $billIds))->sum(fn (SupplierBill $bill): float => (float) $bill->balanceDue()), '0', 2);

        return Action::make('paySupplier')
            ->label('Pay supplier')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('success')
            ->authorize(fn (): bool => Gate::allows('create', SupplierPayment::class))
            ->modalWidth('2xl')
            ->modalSubmitActionLabel('Record payment')
            ->fillForm(function () use ($vendor, $bill, $openBills): array {
                $billIds = $bill ? [$bill->id] : ($vendor ? $openBills($vendor->id)->pluck('id')->all() : []);

                return [
                    'vendor_id' => $vendor?->id,
                    'bill_ids' => $billIds,
                    'amount' => $bill ? $bill->balanceDue() : ($vendor ? $vendor->balance() : null),
                    'method' => ($vendor?->payment_method ?? PaymentMethod::BankTransfer)->value,
                    'paid_on' => today(),
                ];
            })
            ->schema([
                Select::make('vendor_id')
                    ->label('Supplier')
                    ->options(fn (): array => Vendor::query()->whereHas('bills', fn (Builder $query) => $query->outstanding())->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set) use ($openBills): void {
                        $bills = $openBills($state ? (int) $state : null);
                        $set('bill_ids', $bills->pluck('id')->all());
                        $set('amount', bcadd((string) $bills->sum(fn (SupplierBill $bill): float => (float) $bill->balanceDue()), '0', 2));
                        $set('method', (Vendor::query()->find($state)?->payment_method ?? PaymentMethod::BankTransfer)->value);
                    })
                    ->visible($vendor === null),
                Text::make(function (Get $get) use ($vendorId): string {
                    $payee = Vendor::query()->find($vendorId($get));

                    return $payee === null ? 'Choose a supplier.' : 'Pay to: '.($payee->paymentDetails() ?? 'no payment details on file').($payee->kra_pin ? " · KRA PIN {$payee->kra_pin}" : '');
                })->color('gray'),
                CheckboxList::make('bill_ids')
                    ->label('Bills being paid')
                    ->options(fn (Get $get): array => $openBills($vendorId($get))->mapWithKeys(fn (SupplierBill $bill): array => [$bill->id => "{$bill->number} · inv {$bill->vendor_invoice_number} · KES ".Number::format((float) $bill->balanceDue(), 2)])->all())
                    ->descriptions(fn (Get $get): array => $openBills($vendorId($get))->mapWithKeys(fn (SupplierBill $bill): array => [$bill->id => "{$bill->description} · due {$bill->due_date->format('j M Y')}".($bill->isOverdue() ? ' (overdue)' : '')])->all())
                    ->bulkToggleable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => $set('amount', $selectedTotal($vendorId($get), $get('bill_ids') ?? []))),
                ToggleButtons::make('method')
                    ->options(collect(PaymentMethod::disbursable())->mapWithKeys(fn (PaymentMethod $method): array => [$method->value => $method->getLabel()])->all())
                    ->icons([
                        PaymentMethod::BankTransfer->value => Heroicon::OutlinedBuildingLibrary,
                        PaymentMethod::Mpesa->value => Heroicon::OutlinedDevicePhoneMobile,
                        PaymentMethod::Cheque->value => Heroicon::OutlinedDocumentText,
                        PaymentMethod::Cash->value => Heroicon::OutlinedBanknotes,
                    ])
                    ->inline()
                    ->required()
                    ->live(),
                TextInput::make('amount')
                    ->numeric()
                    ->minValue(0.01)
                    ->rule('decimal:0,2')
                    ->prefix('KES')
                    ->required()
                    ->helperText('Less than the bills\' total pays them part-way, earliest due first.'),
                DatePicker::make('paid_on')->label('Date paid')->required()->maxDate(today()),
                TextInput::make('reference')
                    ->label(fn (Get $get): string => match ($get('method')) {
                        PaymentMethod::Mpesa->value, PaymentMethod::Mpesa => 'M-Pesa transaction code',
                        PaymentMethod::Cheque->value, PaymentMethod::Cheque => 'Cheque number',
                        PaymentMethod::BankTransfer->value, PaymentMethod::BankTransfer => 'Bank reference',
                        default => 'Reference',
                    })
                    ->required(fn (Get $get): bool => in_array($get('method'), [PaymentMethod::Mpesa->value, PaymentMethod::Cheque->value, PaymentMethod::Mpesa, PaymentMethod::Cheque], true))
                    ->maxLength(100),
                Textarea::make('notes')->rows(2),
            ])
            ->action(function (array $data, SupplierPaymentService $payments, Action $action) use ($vendor): void {
                $payee = $vendor ?? Vendor::query()->findOrFail($data['vendor_id']);

                try {
                    $payment = $payments->pay(
                        $payee,
                        $data['paid_on'],
                        PaymentMethod::from($data['method'] instanceof PaymentMethod ? $data['method']->value : $data['method']),
                        (string) $data['amount'],
                        SupplierBill::query()->whereKey($data['bill_ids'])->get(),
                        $data['reference'] ?? null,
                        $data['notes'] ?? null,
                    );
                } catch (ProcurementActionRefused|InvalidJournalEntry $exception) {
                    Notification::make()->title('Payment not recorded')->body($exception->getMessage())->danger()->persistent()->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title("Payment {$payment->number} recorded")
                    ->body('KES '.Number::format((float) $payment->amount, 2).' to '.$payee->name.'.')
                    ->success()
                    ->actions([Action::make('view')->url(self::getUrl('view', ['record' => $payment]))])
                    ->send();
            });
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
                            ->label('Paid to')
                            ->weight('bold')
                            ->url(fn (SupplierPayment $record): string => VendorResource::getUrl('view', ['record' => $record->vendor_id])),
                        TextEntry::make('paid_on')->date(),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('method'),
                        TextEntry::make('reference')->fontFamily(FontFamily::Mono)->copyable()->placeholder('-'),
                        TextEntry::make('paidFromAccount.name')->label('Paid from'),
                        TextEntry::make('notes')->placeholder('-')->columnSpanFull(),
                        TextEntry::make('creator.name')->label('Recorded by')->placeholder('-'),
                    ]),
                Section::make()
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('amount')->money('KES')->size('lg')->weight('bold'),
                    ]),
                RepeatableEntry::make('allocations')
                    ->label('Bills settled')
                    ->columnSpanFull()
                    ->visible(fn (SupplierPayment $record): bool => $record->allocations()->exists())
                    ->table([EntryColumn::make('Bill'), EntryColumn::make('Supplier invoice'), EntryColumn::make('Description'), EntryColumn::make('Applied')])
                    ->schema([
                        TextEntry::make('bill.number')->url(fn ($record): string => SupplierBillResource::getUrl('view', ['record' => $record->supplier_bill_id])),
                        TextEntry::make('bill.vendor_invoice_number'),
                        TextEntry::make('bill.description'),
                        TextEntry::make('amount')->money('KES'),
                    ]),
                Section::make('Accounting')
                    ->columnSpanFull()
                    ->collapsed()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('journalEntry.number')
                            ->label('Posted as')
                            ->url(fn (SupplierPayment $record): ?string => $record->journal_entry_id ? JournalEntryResource::getUrl('view', ['record' => $record->journal_entry_id]) : null),
                        TextEntry::make('reversed_at')->dateTime()->placeholder('-'),
                        TextEntry::make('reversal_reason')->placeholder('-'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('vendor'))
            ->defaultSort('paid_on', 'desc')
            ->columns([
                TextColumn::make('number')->searchable()->sortable()->weight('semibold'),
                TextColumn::make('vendor.name')->label('Supplier')->searchable()->hidden(fn ($livewire): bool => $livewire instanceof RelationManager),
                TextColumn::make('paid_on')->date()->sortable(),
                TextColumn::make('method'),
                TextColumn::make('reference')->searchable()->fontFamily(FontFamily::Mono)->placeholder('-'),
                TextColumn::make('amount')->money('KES')->alignEnd()->sortable()->summarize(Sum::make()->money('KES')->label('Total')),
                TextColumn::make('status')->badge(),
            ])
            ->filters([
                SelectFilter::make('vendor')->label('Supplier')->relationship('vendor', 'name')->searchable()->preload(),
                SelectFilter::make('method')->options(collect(PaymentMethod::disbursable())->mapWithKeys(fn (PaymentMethod $method): array => [$method->value => $method->getLabel()])->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('No supplier payments')
            ->emptyStateDescription('Pay suppliers from a bill, a supplier\'s page, or the button above.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplierPayments::route('/'),
            'view' => ViewSupplierPayment::route('/{record}'),
        ];
    }
}
