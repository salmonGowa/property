<?php

namespace App\Filament\Resources\Payments;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Resources\Tenants\TenantResource;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\Collections\PaymentService;
use App\Services\Ledger\InvalidJournalEntry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
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
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use UnitEnum;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Billing & Collections';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'reference', 'tenant.name'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['Tenant' => $record->tenant->name, 'Amount' => 'KES '.Number::format((float) $record->amount, 2), 'Reference' => $record->reference];
    }

    /**
     * "Record payment" modal, usable from the payments list, a tenant or an invoice.
     */
    public static function recordPaymentAction(?Tenant $tenant = null, ?Invoice $invoice = null): Action
    {
        $tenantId = fn (Get $get): ?int => $tenant?->id ?? ($get('tenant_id') ? (int) $get('tenant_id') : null);

        return Action::make('recordPayment')
            ->label('Record payment')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->authorize(fn (): bool => Gate::allows('create', Payment::class))
            ->modalWidth('2xl')
            ->modalSubmitActionLabel('Receipt payment')
            ->fillForm(fn (): array => [
                'tenant_id' => $tenant?->id,
                'received_on' => today(),
                'method' => PaymentMethod::Mpesa->value,
                'amount' => $invoice ? $invoice->balanceDue() : null,
            ])
            ->schema([
                Select::make('tenant_id')
                    ->label('Tenant')
                    ->options(fn (): array => Tenant::query()->where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn (Tenant $tenant): array => [$tenant->id => "{$tenant->name} ({$tenant->account_number})"])->all())
                    ->searchable()
                    ->required()
                    ->live()
                    ->visible($tenant === null),
                Text::make(function (Get $get) use ($tenantId): string {
                    $id = $tenantId($get);

                    if ($id === null) {
                        return 'Choose a tenant to see what they owe.';
                    }

                    $outstanding = Invoice::query()->where('tenant_id', $id)->outstanding()->orderBy('due_date')->get();

                    if ($outstanding->isEmpty()) {
                        return 'No outstanding invoices. Anything received is held as credit for the next invoice.';
                    }

                    return 'Outstanding: KES '.Number::format($outstanding->sum(fn (Invoice $invoice): float => (float) $invoice->balanceDue()), 2)
                        .' across '.$outstanding->count().' invoice(s), oldest due '.$outstanding->first()->due_date->format('j M Y').'. Payment is applied oldest first.';
                })->color('gray'),
                ToggleButtons::make('method')
                    ->options(collect(PaymentMethod::receivable())->mapWithKeys(fn (PaymentMethod $method): array => [$method->value => $method->getLabel()])->all())
                    ->icons([
                        PaymentMethod::Mpesa->value => Heroicon::OutlinedDevicePhoneMobile,
                        PaymentMethod::BankTransfer->value => Heroicon::OutlinedBuildingLibrary,
                        PaymentMethod::Cheque->value => Heroicon::OutlinedDocumentText,
                        PaymentMethod::Cash->value => Heroicon::OutlinedBanknotes,
                        PaymentMethod::WithholdingTax->value => Heroicon::OutlinedReceiptPercent,
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
                    ->suffixAction(Action::make('fillBalance')
                        ->icon(Heroicon::OutlinedArrowDownTray)
                        ->tooltip('Fill with full balance')
                        ->action(function (Get $get, Set $set) use ($tenantId): void {
                            $id = $tenantId($get);
                            $set('amount', $id ? max(0, (float) Tenant::query()->find($id)?->balance()) : null);
                        })),
                DatePicker::make('received_on')
                    ->label('Date received')
                    ->required()
                    ->maxDate(today()),
                TextInput::make('reference')
                    ->label(fn (Get $get): string => match ($get('method')) {
                        PaymentMethod::Mpesa->value, PaymentMethod::Mpesa => 'M-Pesa transaction code',
                        PaymentMethod::Cheque->value, PaymentMethod::Cheque => 'Cheque number',
                        PaymentMethod::BankTransfer->value, PaymentMethod::BankTransfer => 'Bank reference',
                        PaymentMethod::WithholdingTax->value, PaymentMethod::WithholdingTax => 'WHT certificate number',
                        default => 'Reference',
                    })
                    ->placeholder(fn (Get $get): ?string => in_array($get('method'), [PaymentMethod::Mpesa->value, PaymentMethod::Mpesa], true) ? 'e.g. SJK3H5T9QW' : null)
                    ->required(fn (Get $get): bool => in_array($get('method'), [PaymentMethod::Mpesa->value, PaymentMethod::Mpesa], true))
                    ->maxLength(100),
                Textarea::make('notes')->rows(2),
            ])
            ->action(function (array $data, PaymentService $payments, Action $action) use ($tenant): void {
                try {
                    $payment = $payments->record(
                        $tenant ?? Tenant::query()->findOrFail($data['tenant_id']),
                        $data['received_on'],
                        PaymentMethod::from($data['method'] instanceof PaymentMethod ? $data['method']->value : $data['method']),
                        (string) $data['amount'],
                        $data['reference'] ?? null,
                        $data['notes'] ?? null,
                    );
                } catch (InvalidJournalEntry $exception) {
                    Notification::make()->title('Payment not recorded')->body($exception->getMessage())->danger()->persistent()->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title("Receipt {$payment->number} recorded")
                    ->body('KES '.Number::format((float) $payment->amount, 2).' from '.$payment->tenant->name.'.')
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
                        TextEntry::make('tenant.name')
                            ->label('Received from')
                            ->weight('bold')
                            ->url(fn (Payment $record): string => TenantResource::getUrl('view', ['record' => $record->tenant_id])),
                        TextEntry::make('received_on')->date(),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('method'),
                        TextEntry::make('reference')->fontFamily(FontFamily::Mono)->copyable()->placeholder('-'),
                        TextEntry::make('depositAccount.name')->label('Deposited to'),
                        TextEntry::make('creator.name')->label('Recorded by')->placeholder('M-Pesa (automatic)'),
                        TextEntry::make('notes')->placeholder('-')->columnSpan(2),
                    ]),
                Section::make('Amounts')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('amount')->money('KES')->size('lg')->weight('bold')->inlineLabel(),
                        TextEntry::make('unallocated')
                            ->label('Unapplied credit')
                            ->state(fn (Payment $record): string => $record->unallocatedAmount())
                            ->money('KES')
                            ->inlineLabel()
                            ->helperText('Applied automatically to the next invoice.'),
                    ]),
                RepeatableEntry::make('allocations')
                    ->label('Applied to invoices')
                    ->columnSpanFull()
                    ->visible(fn (Payment $record): bool => $record->allocations()->exists())
                    ->table([
                        EntryColumn::make('Invoice'),
                        EntryColumn::make('Description'),
                        EntryColumn::make('Applied'),
                    ])
                    ->schema([
                        TextEntry::make('invoice.number')->url(fn ($record): string => InvoiceResource::getUrl('view', ['record' => $record->invoice_id])),
                        TextEntry::make('invoice.description'),
                        TextEntry::make('amount')->money('KES'),
                    ]),
                Section::make('Reversal')
                    ->columnSpanFull()
                    ->columns(3)
                    ->visible(fn (Payment $record): bool => $record->status === PaymentStatus::Reversed)
                    ->schema([
                        TextEntry::make('reversed_at')->dateTime(),
                        TextEntry::make('reversal_reason')->label('Reason'),
                    ]),
                Section::make('Accounting')
                    ->columnSpanFull()
                    ->collapsed()
                    ->schema([
                        TextEntry::make('journalEntry.number')
                            ->label('Posted as')
                            ->url(fn (Payment $record): ?string => $record->journal_entry_id ? JournalEntryResource::getUrl('view', ['record' => $record->journal_entry_id]) : null),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('tenant')->withSum('allocations', 'amount'))
            ->defaultSort('received_on', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->label('Receipt')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),
                TextColumn::make('received_on')->label('Date')->date()->sortable(),
                TextColumn::make('tenant.name')
                    ->searchable()
                    ->hidden(fn ($livewire): bool => $livewire instanceof RelationManager),
                TextColumn::make('method')->badge()->color('gray'),
                TextColumn::make('reference')
                    ->searchable()
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->placeholder('-'),
                TextColumn::make('amount')
                    ->money('KES')
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Sum::make()->money('KES')->label('Total')->query(fn ($query) => $query->where('status', PaymentStatus::Posted->value))),
                TextColumn::make('unallocated')
                    ->label('Credit')
                    ->state(fn (Payment $record): string => $record->status === PaymentStatus::Posted ? bcsub($record->amount, (string) ($record->allocations_sum_amount ?? '0'), 2) : '0.00')
                    ->formatStateUsing(fn (string $state): string => bccomp($state, '0', 2) > 0 ? Number::format((float) $state, 2) : '')
                    ->alignEnd()
                    ->color('success')
                    ->toggleable(),
                TextColumn::make('status')->badge(),
            ])
            ->filters([
                SelectFilter::make('method')->options(PaymentMethod::class),
                SelectFilter::make('status')->options(PaymentStatus::class),
                Filter::make('received')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('received_on', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('received_on', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('No payments recorded');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
            'view' => ViewPayment::route('/{record}'),
        ];
    }
}
