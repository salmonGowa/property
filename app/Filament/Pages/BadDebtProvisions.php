<?php

namespace App\Filament\Pages;

use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Models\BadDebtProvision;
use App\Services\Arrears\ArrearsAging;
use App\Services\Debt\BadDebtProvisionService;
use App\Services\Ledger\InvalidJournalEntry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use UnitEnum;

class BadDebtProvisions extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.arrears-aging-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'Billing & Collections';

    protected static ?string $navigationLabel = 'Bad Debt Provision';

    protected static ?string $title = 'Bad Debt Provision';

    protected static ?int $navigationSort = 8;

    public static function canAccess(): bool
    {
        return Auth::user()?->canViewLedger() ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'The allowance for doubtful debts is a percentage of tenant arrears in each aging bucket. Only the change from the current allowance is posted.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('provide')
                ->label('Calculate provision')
                ->icon(Heroicon::OutlinedCalculator)
                ->authorize(fn (): bool => Gate::allows('create', BadDebtProvision::class))
                ->modalWidth('2xl')
                ->modalSubmitActionLabel('Post adjustment')
                ->fillForm(fn (BadDebtProvisionService $provisions): array => ['rates' => $provisions->currentRates(), 'as_of' => today()])
                ->schema([
                    ...collect(ArrearsAging::BUCKETS)->map(fn (string $label, string $key): TextInput => TextInput::make("rates.{$key}")
                        ->label($label)
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->suffix('%')
                        ->required()
                        ->live(onBlur: true)
                        ->inlineLabel())->values()->all(),
                    Text::make(function (Get $get, BadDebtProvisionService $provisions): string {
                        $result = $provisions->calculate($get('rates') ?? []);
                        $adjustment = (float) $result['adjustment'];

                        return 'Arrears KES '.Number::format((float) array_sum($result['buckets']), 2)
                            .' · Allowance needed KES '.Number::format((float) $result['required'], 2)
                            .' · Held now KES '.Number::format((float) $result['previous'], 2)
                            .' · '.($adjustment >= 0 ? 'Charge to bad debts' : 'Release to bad debts').' KES '.Number::format(abs($adjustment), 2);
                    })->weight(FontWeight::SemiBold),
                    DatePicker::make('as_of')->label('Post on')->maxDate(today())->required(),
                ])
                ->action(function (array $data, BadDebtProvisionService $provisions): void {
                    try {
                        $provision = $provisions->post($data['rates'], $data['as_of']);
                    } catch (InvalidJournalEntry $exception) {
                        Notification::make()->title('Nothing posted')->body($exception->getMessage())->warning()->send();

                        return;
                    }

                    Notification::make()->title('Allowance set to KES '.Number::format((float) $provision->required, 2))->success()->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(BadDebtProvision::query()->with(['journalEntry', 'creator']))
            ->defaultSort('as_of', 'desc')
            ->columns([
                TextColumn::make('as_of')->label('Date')->date(),
                TextColumn::make('rates')
                    ->label('Rates (current / 1-30 / 31-60 / 61-90 / 90+)')
                    ->formatStateUsing(fn (BadDebtProvision $record): string => collect(ArrearsAging::BUCKETS)->keys()->map(fn (string $key): string => rtrim(rtrim((string) ($record->rates[$key] ?? '0'), '0'), '.').'%')->implode(' / ')),
                TextColumn::make('required')->label('Allowance')->money('KES')->alignEnd()->weight('semibold'),
                TextColumn::make('adjustment')->money('KES')->alignEnd()->color(fn (BadDebtProvision $record): string => (float) $record->adjustment >= 0 ? 'danger' : 'success'),
                TextColumn::make('creator.name')->label('By')->placeholder('-'),
                TextColumn::make('journalEntry.number')
                    ->label('Posted as')
                    ->url(fn (BadDebtProvision $record): ?string => $record->journal_entry_id ? JournalEntryResource::getUrl('view', ['record' => $record->journal_entry_id]) : null),
            ])
            ->emptyStateHeading('No provisions yet')
            ->emptyStateDescription('Calculate a provision at each month or year end from the aged arrears.');
    }
}
