<?php

namespace App\Filament\Resources\CreditNotes;

use App\Enums\ChargeType;
use App\Enums\CreditNoteType;
use App\Enums\InvoiceStatus;
use App\Filament\Resources\CreditNotes\Pages\ListCreditNotes;
use App\Filament\Resources\CreditNotes\Pages\ViewCreditNote;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Resources\Tenants\TenantResource;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Services\Billing\CreditNoteService;
use App\Services\Ledger\InvalidJournalEntry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn as EntryColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use UnitEnum;

class CreditNoteResource extends Resource
{
    protected static ?string $model = CreditNote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static string|UnitEnum|null $navigationGroup = 'Billing & Collections';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'number';

    /**
     * Credit an invoice. The penalty-waiver and write-off shortcuts preset the type and full balance.
     */
    public static function issueAction(Invoice $invoice, string $name = 'issueCreditNote', ?CreditNoteType $presetType = null): Action
    {
        $isPenaltyInvoice = $invoice->lines->every(fn ($line): bool => $line->charge_type === ChargeType::Penalty);

        return Action::make($name)
            ->label(match ($presetType) {
                CreditNoteType::PenaltyWaiver => 'Waive penalty',
                CreditNoteType::WriteOff => 'Write off',
                default => 'Issue credit note',
            })
            ->icon(match ($presetType) {
                CreditNoteType::PenaltyWaiver => Heroicon::OutlinedHandRaised,
                CreditNoteType::WriteOff => Heroicon::OutlinedArchiveBoxXMark,
                default => Heroicon::OutlinedReceiptRefund,
            })
            ->color($presetType === CreditNoteType::WriteOff ? 'danger' : 'warning')
            ->authorize(fn (): bool => Gate::allows('create', CreditNote::class))
            ->visible(fn (): bool => $invoice->status !== InvoiceStatus::Void
                && bccomp(app(CreditNoteService::class)->creditableAmount($invoice), '0', 2) > 0
                && ($presetType !== CreditNoteType::PenaltyWaiver || $isPenaltyInvoice))
            ->modalDescription(match ($presetType) {
                CreditNoteType::PenaltyWaiver => 'Cancels the unpaid penalty. The waiver and your reason are kept on record.',
                CreditNoteType::WriteOff => 'Use when the debt will not be recovered. The amount is charged to Bad Debts; rent income stays as billed.',
                default => 'Reduces what the tenant owes on this invoice and reverses the income and VAT in proportion. Credit beyond the unpaid balance is applied to their next invoice.',
            })
            ->fillForm(fn (): array => [
                'type' => ($presetType ?? ($isPenaltyInvoice ? CreditNoteType::PenaltyWaiver : CreditNoteType::Adjustment))->value,
                'amount' => bccomp($invoice->balanceDue(), '0', 2) > 0 ? $invoice->balanceDue() : app(CreditNoteService::class)->creditableAmount($invoice),
                'issue_date' => today(),
            ])
            ->schema([
                Select::make('type')
                    ->options(collect(CreditNoteType::cases())->reject(fn (CreditNoteType $type): bool => $type === CreditNoteType::Termination)->mapWithKeys(fn (CreditNoteType $type): array => [$type->value => $type->getLabel()])->all())
                    ->required()
                    ->live()
                    ->visible($presetType === null),
                TextInput::make('amount')
                    ->label('Amount to credit (incl. VAT)')
                    ->numeric()
                    ->minValue(0.01)
                    ->maxValue((float) app(CreditNoteService::class)->creditableAmount($invoice))
                    ->rule('decimal:0,2')
                    ->prefix('KES')
                    ->required()
                    ->helperText('Up to KES '.Number::format((float) app(CreditNoteService::class)->creditableAmount($invoice), 2).' can be credited on this invoice.'),
                DatePicker::make('issue_date')->required()->maxDate(today()),
                TextInput::make('reason')
                    ->required()
                    ->maxLength(255)
                    ->placeholder(fn (Get $get): string => match ($get('type')) {
                        CreditNoteType::WriteOff->value => 'e.g. Tenant absconded; demand letters unanswered',
                        CreditNoteType::PenaltyWaiver->value => 'e.g. First late payment, waived as goodwill',
                        default => 'e.g. Rent agreed at KES 38,000 not 40,000',
                    }),
                Text::make(fn (): string => 'Approved by you as '.(Auth::user()?->role?->getLabel() ?? 'user').'; recorded in the audit trail.')->color('gray'),
            ])
            ->action(function (array $data, CreditNoteService $creditNotes) use ($invoice, $presetType): void {
                try {
                    $creditNote = $creditNotes->issue(
                        $invoice,
                        $presetType ?? CreditNoteType::from($data['type']),
                        (string) $data['amount'],
                        $data['reason'],
                        $data['issue_date'],
                    );
                } catch (InvalidJournalEntry $exception) {
                    Notification::make()->title('Credit note not issued')->body($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title("{$creditNote->number} issued")
                    ->body('KES '.Number::format((float) $creditNote->amount, 2).' credited on '.$invoice->number.'.')
                    ->success()
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
                        TextEntry::make('tenant.name')->weight('bold')->url(fn (CreditNote $record): string => TenantResource::getUrl('view', ['record' => $record->tenant_id])),
                        TextEntry::make('invoice.number')->label('Credits invoice')->url(fn (CreditNote $record): string => InvoiceResource::getUrl('view', ['record' => $record->invoice_id])),
                        TextEntry::make('type')->badge(),
                        TextEntry::make('reason')->columnSpanFull(),
                        TextEntry::make('issue_date')->date(),
                        TextEntry::make('creator.name')->label('Issued by')->placeholder('System'),
                        TextEntry::make('journalEntry.number')->label('Posted as')->url(fn (CreditNote $record): ?string => $record->journal_entry_id ? JournalEntryResource::getUrl('view', ['record' => $record->journal_entry_id]) : null),
                    ]),
                Section::make('Amounts')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('amount')->money('KES')->size('lg')->weight('bold')->inlineLabel(),
                        TextEntry::make('vat_amount')->label('of which VAT')->money('KES')->inlineLabel(),
                    ]),
                RepeatableEntry::make('allocations')
                    ->label('Applied to')
                    ->columnSpanFull()
                    ->table([EntryColumn::make('Invoice'), EntryColumn::make('Description'), EntryColumn::make('Applied')])
                    ->schema([
                        TextEntry::make('invoice.number')->url(fn ($record): string => InvoiceResource::getUrl('view', ['record' => $record->invoice_id])),
                        TextEntry::make('invoice.description'),
                        TextEntry::make('amount')->money('KES'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['tenant', 'invoice', 'creator']))
            ->defaultSort('issue_date', 'desc')
            ->columns([
                TextColumn::make('number')->searchable()->sortable()->weight('semibold'),
                TextColumn::make('issue_date')->label('Date')->date()->sortable(),
                TextColumn::make('tenant.name')->searchable()->hidden(fn ($livewire): bool => $livewire instanceof RelationManager),
                TextColumn::make('invoice.number')->label('Invoice')->searchable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('reason')->limit(40)->tooltip(fn (CreditNote $record): string => $record->reason),
                TextColumn::make('amount')->money('KES')->alignEnd()->summarize(Sum::make()->money('KES')->label('Total')),
                TextColumn::make('creator.name')->label('By')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')->options(CreditNoteType::class),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('No credit notes')
            ->emptyStateDescription('Issue credit notes, waive penalties or write off debt from an invoice.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCreditNotes::route('/'),
            'view' => ViewCreditNote::route('/{record}'),
        ];
    }
}
