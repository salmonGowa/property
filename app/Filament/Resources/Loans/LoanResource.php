<?php

namespace App\Filament\Resources\Loans;

use App\Enums\AccountType;
use App\Enums\BillingFrequency;
use App\Enums\InterestRateType;
use App\Enums\LoanStatus;
use App\Enums\LoanType;
use App\Enums\RepaymentType;
use App\Filament\Resources\Loans\Pages\CreateLoan;
use App\Filament\Resources\Loans\Pages\EditLoan;
use App\Filament\Resources\Loans\Pages\ListLoans;
use App\Filament\Resources\Loans\Pages\ViewLoan;
use App\Filament\Resources\Loans\RelationManagers\CovenantsRelationManager;
use App\Filament\Resources\Loans\RelationManagers\DrawdownsRelationManager;
use App\Filament\Resources\Loans\RelationManagers\InstalmentsRelationManager;
use App\Filament\Resources\Loans\RelationManagers\RateChangesRelationManager;
use App\Filament\Resources\Loans\RelationManagers\RepaymentsRelationManager;
use App\Filament\Resources\Properties\PropertyResource;
use App\Models\Account;
use App\Models\Lender;
use App\Models\Loan;
use App\Models\LoanCovenant;
use App\Models\Property;
use App\Services\Debt\CovenantService;
use App\Services\Debt\InterestAccrualService;
use App\Services\Debt\LoanService;
use App\Services\Money;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn as EntryColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Throwable;
use UnitEnum;

class LoanResource extends Resource
{
    protected static ?string $model = Loan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Debt & Financing';

    protected static ?string $navigationLabel = 'Loans & Facilities';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['reference', 'name', 'lender_reference', 'lender.name'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['Lender' => $record->lender->name, 'Reference' => $record->reference];
    }

    public static function getNavigationBadge(): ?string
    {
        $overdue = Loan::query()->active()->whereHas('nextInstalment', fn (Builder $query) => $query->whereDate('due_date', '<', today()))->count();

        return $overdue > 0 ? (string) $overdue : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Loans with an overdue instalment';
    }

    /**
     * Bank, M-Pesa and cash accounts loan money moves through.
     *
     * @return array<int, string>
     */
    public static function bankAccountOptions(): array
    {
        return Account::query()->postable()->where('type', AccountType::Asset)->where('code', 'like', '11%')->orderBy('code')->get()
            ->mapWithKeys(fn (Account $account): array => [$account->id => "{$account->code} {$account->name}"])->all();
    }

    /**
     * Plain-English summary of the schedule the form would produce, if the whole facility were drawn at the start.
     */
    public static function schedulePreview(Get $get): string
    {
        try {
            $loan = new Loan([
                'facility_amount' => Money::round((string) $get('facility_amount')),
                'start_date' => Carbon::parse($get('start_date')),
                'first_payment_date' => Carbon::parse($get('first_payment_date')),
                'maturity_date' => Carbon::parse($get('maturity_date')),
                'payment_frequency' => $get('payment_frequency'),
                'repayment_type' => $get('repayment_type'),
                'interest_only_periods' => (int) $get('interest_only_periods'),
                'balloon_amount' => Money::round((string) ($get('balloon_amount') ?: '0')),
                'interest_rate' => self::rateFrom($get),
            ]);

            if (! Money::isPositive((string) $loan->facility_amount) || $loan->first_payment_date->lte($loan->start_date) || $loan->maturity_date->lt($loan->first_payment_date)) {
                return 'Enter the amount and dates to see the repayment schedule.';
            }

            $rows = app(LoanService::class)->projectedSchedule($loan);
        } catch (Throwable) {
            return 'Enter the amount, rate and dates to see the repayment schedule.';
        }

        $regular = $rows[min(count($rows) - 1, (int) $get('interest_only_periods'))];
        $interest = Money::sum(array_column($rows, 'interest'));
        $last = end($rows);

        return count($rows).' instalments, '.strtolower($loan->payment_frequency->getLabel()).', from '.$rows[0]['due_date']->format('j M Y')
            .'. Regular payment about KES '.Number::format((float) $regular['total'], 2)
            .', final payment KES '.Number::format((float) $last['total'], 2)
            .'. Total interest about KES '.Number::format((float) $interest, 2).' if fully drawn at the start.';
    }

    private static function rateFrom(Get $get): string
    {
        $type = $get('rate_type') instanceof InterestRateType ? $get('rate_type') : InterestRateType::tryFrom((string) $get('rate_type'));

        return $type === InterestRateType::Variable
            ? bcadd((string) ($get('base_rate') ?: '0'), (string) ($get('margin') ?: '0'), 3)
            : bcadd((string) ($get('interest_rate') ?: '0'), '0', 3);
    }

    public static function form(Schema $schema): Schema
    {
        $isVariable = fn (Get $get): bool => in_array($get('rate_type'), [InterestRateType::Variable, InterestRateType::Variable->value], true);
        $isInterestOnly = fn (Get $get): bool => in_array($get('repayment_type'), [RepaymentType::InterestOnly, RepaymentType::InterestOnly->value], true);
        $termsLocked = fn (?Loan $record): bool => $record !== null && $record->status !== LoanStatus::Pending;

        return $schema
            ->columns(3)
            ->components([
                Callout::make('Terms are locked')
                    ->description('This loan has been drawn, so its amount and repayment terms cannot be edited. Use "Change rate" for repricing; other changes need a new facility.')
                    ->info()
                    ->visible($termsLocked)
                    ->columnSpanFull(),
                Section::make('Facility')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        Select::make('lender_id')
                            ->label('Lender')
                            ->options(fn (): array => Lender::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required()
                            ->disabled($termsLocked)
                            ->helperText('Add banks under Debt & Financing → Lenders.'),
                        TextInput::make('name')->required()->maxLength(255)->placeholder('e.g. Westlands Business Park mortgage'),
                        Radio::make('type')
                            ->options(LoanType::class)
                            ->default(LoanType::SeniorTerm->value)
                            ->required()
                            ->disabled($termsLocked)
                            ->columnSpanFull(),
                        TextInput::make('facility_amount')
                            ->label('Facility amount')
                            ->numeric()
                            ->minValue(1)
                            ->rule('decimal:0,2')
                            ->prefix('KES')
                            ->required()
                            ->live(onBlur: true)
                            ->disabled($termsLocked),
                        TextInput::make('lender_reference')->label('Lender\'s loan account number')->maxLength(255),
                        Select::make('properties')
                            ->label('Security (properties charged)')
                            ->options(fn (): array => Property::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->multiple()
                            ->helperText('Used for loan-to-value and to measure NOI for this loan. Leave empty for a corporate loan tested on the whole portfolio.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Interest')
                    ->columnSpan(1)
                    ->schema([
                        ToggleButtons::make('rate_type')
                            ->hiddenLabel()
                            ->options(InterestRateType::class)
                            ->default(InterestRateType::Fixed->value)
                            ->required()
                            ->live()
                            ->disabled($termsLocked),
                        TextInput::make('interest_rate')
                            ->label('Annual rate')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(99)
                            ->suffix('%')
                            ->required(fn (Get $get): bool => ! $isVariable($get))
                            ->visible(fn (Get $get): bool => ! $isVariable($get))
                            ->live(onBlur: true)
                            ->disabled($termsLocked),
                        TextInput::make('base_rate_name')->label('Base rate')->default('CBK Rate')->visible($isVariable)->disabled($termsLocked),
                        TextInput::make('base_rate')->label('Base rate now')->numeric()->suffix('%')->required($isVariable)->visible($isVariable)->live(onBlur: true)->disabled($termsLocked),
                        TextInput::make('margin')->numeric()->suffix('%')->required($isVariable)->visible($isVariable)->live(onBlur: true)->disabled($termsLocked)
                            ->helperText(fn (Get $get): string => 'All-in rate '.rtrim(rtrim(self::rateFrom($get), '0'), '.').'%'),
                    ]),
                Section::make('Repayment')
                    ->columnSpanFull()
                    ->columns(4)
                    ->schema([
                        DatePicker::make('start_date')->label('Agreement / start date')->required()->live()->disabled($termsLocked),
                        DatePicker::make('first_payment_date')->label('First instalment due')->required()->after('start_date')->live()->disabled($termsLocked),
                        DatePicker::make('maturity_date')->label('Maturity')->required()->afterOrEqual('first_payment_date')->live()->disabled($termsLocked),
                        Select::make('payment_frequency')->label('Pay every')->options(BillingFrequency::class)->default(BillingFrequency::Monthly->value)->required()->live()->disabled($termsLocked),
                        Radio::make('repayment_type')
                            ->options(RepaymentType::class)
                            ->default(RepaymentType::Annuity->value)
                            ->required()
                            ->live()
                            ->disabled($termsLocked)
                            ->columnSpan(2),
                        TextInput::make('interest_only_periods')
                            ->label('Interest-only grace')
                            ->integer()
                            ->minValue(0)
                            ->default(0)
                            ->suffix('instalments')
                            ->helperText('A moratorium at the start, common on construction loans.')
                            ->hidden($isInterestOnly)
                            ->live(onBlur: true)
                            ->disabled($termsLocked),
                        TextInput::make('balloon_amount')
                            ->label('Balloon at maturity')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->prefix('KES')
                            ->hidden($isInterestOnly)
                            ->live(onBlur: true)
                            ->disabled($termsLocked),
                        Callout::make(fn (Get $get): string => self::schedulePreview($get))
                            ->info()
                            ->icon(Heroicon::OutlinedCalculator)
                            ->columnSpanFull(),
                    ]),
                Section::make('Accounts')
                    ->columnSpanFull()
                    ->columns(2)
                    ->collapsed()
                    ->schema([
                        Select::make('liability_account_id')
                            ->label('Loan held in')
                            ->options(fn (): array => Account::query()->postable()->where('type', AccountType::Liability)->where('code', 'like', '25%')->orderBy('code')->get()->mapWithKeys(fn (Account $account): array => [$account->id => "{$account->code} {$account->name}"])->all())
                            ->placeholder('Default for the loan type')
                            ->disabled($termsLocked),
                        Select::make('bank_account_id')
                            ->label('Usual bank account')
                            ->options(fn (): array => self::bankAccountOptions())
                            ->placeholder('Bank - Operating Account'),
                    ]),
                Textarea::make('notes')->label('Key terms and notes')->rows(3)->columnSpanFull(),
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
                        TextEntry::make('lender.name')->label('Lender')->weight('bold')->helperText(fn (Loan $record): ?string => $record->lender->contact_person),
                        TextEntry::make('type'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('rate')->label('Interest')->state(fn (Loan $record): string => $record->rateDescription()),
                        TextEntry::make('repayment_type')->label('Repayment')->helperText(fn (Loan $record): string => $record->payment_frequency->getLabel().($record->interest_only_periods > 0 ? ", first {$record->interest_only_periods} interest only" : '')),
                        TextEntry::make('lender_reference')->label('Lender\'s account no.')->copyable()->placeholder('-'),
                        TextEntry::make('start_date')->date(),
                        TextEntry::make('maturity_date')
                            ->label('Maturity')
                            ->date()
                            ->helperText(fn (Loan $record): ?string => $record->status !== LoanStatus::Closed ? 'In '.today()->diffForHumans($record->maturity_date, ['parts' => 2, 'syntax' => Carbon::DIFF_ABSOLUTE]) : null)
                            ->color(fn (Loan $record): ?string => $record->status === LoanStatus::Active && $record->maturity_date->lte(today()->addYear()) ? 'warning' : null),
                        TextEntry::make('balloon_amount')->label('Balloon')->money('KES')->visible(fn (Loan $record): bool => Money::isPositive((string) $record->balloon_amount)),
                        TextEntry::make('properties.name')
                            ->label('Security')
                            ->badge()
                            ->url(fn (Loan $record): ?string => $record->properties->count() === 1 ? PropertyResource::getUrl('view', ['record' => $record->properties->first()]) : null)
                            ->placeholder('Unsecured / corporate')
                            ->columnSpanFull(),
                        TextEntry::make('notes')->label('Key terms')->placeholder('-')->columnSpanFull(),
                    ]),
                Section::make('Balance')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('facility_amount')->label('Facility')->money('KES')->inlineLabel(),
                        TextEntry::make('drawn')->state(fn (Loan $record): string => $record->drawnAmount())->money('KES')->inlineLabel()
                            ->helperText(fn (Loan $record): ?string => Money::isPositive($record->undrawnAmount()) && $record->status !== LoanStatus::Closed ? 'KES '.Number::format((float) $record->undrawnAmount(), 2).' undrawn' : null),
                        TextEntry::make('outstanding')->label('Principal owed')->state(fn (Loan $record): string => $record->outstandingPrincipal())->money('KES')->inlineLabel()->size('lg')->weight('bold'),
                        TextEntry::make('accrued')
                            ->label('Interest built up')
                            ->state(fn (Loan $record): string => app(InterestAccrualService::class)->accruedTo($record, today())['amount'])
                            ->money('KES')
                            ->inlineLabel()
                            ->helperText('Since the last instalment'),
                        TextEntry::make('nextInstalment.due_date')
                            ->label('Next instalment')
                            ->formatStateUsing(fn (Loan $record): string => $record->nextInstalment->due_date->format('j M Y').' · KES '.Number::format((float) $record->nextInstalment->total, 2))
                            ->color(fn (Loan $record): ?string => $record->nextInstalment?->isOverdue() ? 'danger' : null)
                            ->helperText(fn (Loan $record): ?string => $record->nextInstalment?->isOverdue() ? 'Overdue' : null)
                            ->placeholder('-'),
                    ]),
                RepeatableEntry::make('covenants')
                    ->label('Covenants, measured now')
                    ->columnSpanFull()
                    ->visible(fn (Loan $record): bool => $record->covenants()->where('is_active', true)->exists())
                    ->state(fn (Loan $record) => $record->covenants()->where('is_active', true)->get())
                    ->table([EntryColumn::make('Requirement'), EntryColumn::make('Now'), EntryColumn::make('Status'), EntryColumn::make('How it is measured')])
                    ->schema([
                        TextEntry::make('requirement')->state(fn (LoanCovenant $record): string => $record->requirement()),
                        TextEntry::make('now')->state(fn (LoanCovenant $record): string => $record->metric->format(self::measureNow($record)['actual']))->weight('bold'),
                        TextEntry::make('status')
                            ->state(fn (LoanCovenant $record): string => match (self::measureNow($record)['passed']) {
                                true => 'Met',
                                false => 'Breached',
                                null => 'Cannot measure',
                            })
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'Met' => 'success',
                                'Breached' => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('metric')->formatStateUsing(fn (LoanCovenant $record): string => $record->metric->getDescription())->color('gray'),
                    ]),
                Section::make('Covenant inputs')
                    ->description('Trailing 12 months to today. NOI is income billed less credits, write-offs and operating costs on the security properties.')
                    ->columnSpanFull()
                    ->columns(4)
                    ->collapsed()
                    ->visible(fn (Loan $record): bool => $record->status === LoanStatus::Active)
                    ->schema([
                        TextEntry::make('input_noi')->label('Net operating income')->state(fn (Loan $record): string => app(CovenantService::class)->inputs($record, today())['noi'])->money('KES'),
                        TextEntry::make('input_debt_service')->label('Debt service, next 12 months')->state(fn (Loan $record): string => app(CovenantService::class)->inputs($record, today())['debt_service'])->money('KES'),
                        TextEntry::make('input_value')
                            ->label('Security value')
                            ->state(fn (Loan $record): ?string => app(CovenantService::class)->inputs($record, today())['value'])
                            ->money('KES')
                            ->placeholder('No valuation recorded'),
                        TextEntry::make('input_outstanding')->label('Principal owed')->state(fn (Loan $record): string => $record->outstandingPrincipal())->money('KES'),
                    ]),
            ]);
    }

    /**
     * @return array{actual: string|null, passed: bool|null, inputs: array<string, mixed>}
     */
    public static function measureNow(LoanCovenant $covenant): array
    {
        static $results = [];

        return $results[$covenant->id] ??= app(CovenantService::class)->measure($covenant, today());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['lender', 'nextInstalment']))
            ->defaultSort('maturity_date')
            ->columns([
                TextColumn::make('name')
                    ->searchable(['name', 'reference', 'lender_reference'])
                    ->weight('semibold')
                    ->description(fn (Loan $record): string => "{$record->reference} · {$record->lender->name}"),
                TextColumn::make('type')->toggleable(),
                TextColumn::make('outstanding')
                    ->label('Principal owed')
                    ->state(fn (Loan $record): string => $record->outstandingPrincipal())
                    ->money('KES')
                    ->alignEnd()
                    ->summarize(Summarizer::make()->label('Total')->using(fn (): string => 'KES '.Number::format((float) Loan::query()->active()->get()->sum(fn (Loan $loan): float => (float) $loan->outstandingPrincipal()), 2))),
                TextColumn::make('interest_rate')->label('Rate')->formatStateUsing(fn (Loan $record): string => rtrim(rtrim((string) $record->interest_rate, '0'), '.').'%'.($record->rate_type === InterestRateType::Variable ? ' var' : ''))->alignEnd(),
                TextColumn::make('nextInstalment.due_date')
                    ->label('Next instalment')
                    ->date()
                    ->description(fn (Loan $record): ?string => $record->nextInstalment ? 'KES '.Number::format((float) $record->nextInstalment->total, 2) : null)
                    ->color(fn (Loan $record): ?string => $record->nextInstalment?->isOverdue() ? 'danger' : null)
                    ->placeholder('-'),
                TextColumn::make('maturity_date')->label('Maturity')->date()->sortable(),
                TextColumn::make('status')->badge(),
            ])
            ->filters([
                SelectFilter::make('lender')->relationship('lender', 'name'),
                SelectFilter::make('type')->options(LoanType::class),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('No loans recorded')
            ->emptyStateDescription('Record each borrowing facility with its terms; drawdowns and repayments then post to the ledger.');
    }

    public static function getRelations(): array
    {
        return [
            InstalmentsRelationManager::class,
            CovenantsRelationManager::class,
            DrawdownsRelationManager::class,
            RepaymentsRelationManager::class,
            RateChangesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLoans::route('/'),
            'create' => CreateLoan::route('/create'),
            'view' => ViewLoan::route('/{record}'),
            'edit' => EditLoan::route('/{record}/edit'),
        ];
    }
}
