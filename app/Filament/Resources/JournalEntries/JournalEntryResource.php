<?php

namespace App\Filament\Resources\JournalEntries;

use App\Filament\Resources\JournalEntries\Pages\CreateJournalEntry;
use App\Filament\Resources\JournalEntries\Pages\ListJournalEntries;
use App\Filament\Resources\JournalEntries\Pages\ViewJournalEntry;
use App\Models\Account;
use App\Models\JournalEntry;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn as EntryTableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;
use UnitEnum;

class JournalEntryResource extends Resource
{
    protected static ?string $model = JournalEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $navigationLabel = 'Journal';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'number';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                DatePicker::make('entry_date')
                    ->label('Date')
                    ->required()
                    ->default(now()),
                TextInput::make('description')
                    ->required()
                    ->maxLength(255),
                TextInput::make('reference')
                    ->maxLength(255)
                    ->placeholder('Invoice, receipt or cheque number'),
                Repeater::make('lines')
                    ->columnSpanFull()
                    ->minItems(2)
                    ->defaultItems(2)
                    ->addActionLabel('Add line')
                    ->table([
                        TableColumn::make('Account')->markAsRequired(),
                        TableColumn::make('Debit'),
                        TableColumn::make('Credit'),
                        TableColumn::make('Line description'),
                    ])
                    ->schema([
                        Select::make('account_id')
                            ->options(fn (): array => Account::query()->postable()->orderBy('code')->get()
                                ->mapWithKeys(fn (Account $account): array => [$account->id => "{$account->code} {$account->name}"])
                                ->all())
                            ->searchable()
                            ->required(),
                        TextInput::make('debit')
                            ->numeric()
                            ->minValue(0)
                            ->rule('decimal:0,2')
                            ->live(onBlur: true),
                        TextInput::make('credit')
                            ->numeric()
                            ->minValue(0)
                            ->rule('decimal:0,2')
                            ->live(onBlur: true),
                        TextInput::make('description'),
                    ]),
                Text::make(function (Get $get): string {
                    $lines = collect($get('lines') ?? []);
                    $debit = $lines->sum(fn (array $line): float => (float) ($line['debit'] ?? 0));
                    $credit = $lines->sum(fn (array $line): float => (float) ($line['credit'] ?? 0));
                    $difference = round($debit - $credit, 2);

                    return 'Total debits '.Number::format($debit, 2).'  ·  Total credits '.Number::format($credit, 2)
                        .($difference === 0.0 ? '  ·  Balanced' : '  ·  Out of balance by '.Number::format(abs($difference), 2));
                })
                    ->weight(FontWeight::SemiBold)
                    ->columnSpanFull(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('number'),
                        TextEntry::make('entry_date')
                            ->label('Date')
                            ->date(),
                        TextEntry::make('fiscalPeriod.name')
                            ->label('Period'),
                        TextEntry::make('description')
                            ->columnSpan(2),
                        TextEntry::make('reference')
                            ->placeholder('-'),
                        TextEntry::make('poster.name')
                            ->label('Posted by')
                            ->placeholder('System'),
                        TextEntry::make('created_at')
                            ->label('Posted at')
                            ->dateTime(),
                        TextEntry::make('status')
                            ->state(fn (JournalEntry $record): string => self::statusOf($record))
                            ->badge()
                            ->color(fn (string $state): string => $state === 'Posted' ? 'success' : 'warning'),
                    ]),
                RepeatableEntry::make('lines')
                    ->columnSpanFull()
                    ->table([
                        EntryTableColumn::make('Account'),
                        EntryTableColumn::make('Description'),
                        EntryTableColumn::make('Debit'),
                        EntryTableColumn::make('Credit'),
                    ])
                    ->schema([
                        TextEntry::make('account.name')
                            ->formatStateUsing(fn ($record): string => "{$record->account->code} {$record->account->name}"),
                        TextEntry::make('description')
                            ->placeholder('-'),
                        TextEntry::make('debit')
                            ->formatStateUsing(fn (string $state): string => (float) $state === 0.0 ? '' : Number::format((float) $state, 2)),
                        TextEntry::make('credit')
                            ->formatStateUsing(fn (string $state): string => (float) $state === 0.0 ? '' : Number::format((float) $state, 2)),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withSum('lines', 'debit')->withExists('reversal'))
            ->defaultSort('sequence', 'desc')
            ->columns([
                TextColumn::make('sequence')
                    ->label('Number')
                    ->formatStateUsing(fn (int $state): string => JournalEntry::formatNumber($state))
                    ->searchable(query: fn (Builder $query, string $search) => $query->where('sequence', (int) preg_replace('/\D/', '', $search)))
                    ->sortable(),
                TextColumn::make('entry_date')
                    ->label('Date')
                    ->date()
                    ->sortable(),
                TextColumn::make('description')
                    ->searchable()
                    ->limit(60),
                TextColumn::make('reference')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('lines_sum_debit')
                    ->label('Amount (KES)')
                    ->numeric(2)
                    ->alignEnd(),
                TextColumn::make('status')
                    ->state(fn (JournalEntry $record): string => self::statusOf($record))
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Posted' ? 'success' : 'warning'),
            ])
            ->filters([
                SelectFilter::make('fiscal_period_id')
                    ->label('Period')
                    ->relationship('fiscalPeriod', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function statusOf(JournalEntry $entry): string
    {
        return match (true) {
            $entry->reverses_entry_id !== null => 'Reversal',
            $entry->reversal_exists ?? $entry->reversal()->exists() => 'Reversed',
            default => 'Posted',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJournalEntries::route('/'),
            'create' => CreateJournalEntry::route('/create'),
            'view' => ViewJournalEntry::route('/{record}'),
        ];
    }
}
