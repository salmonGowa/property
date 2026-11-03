<?php

namespace App\Filament\Pages;

use App\Services\Ledger\TrialBalance;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use UnitEnum;

class TrialBalanceReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.trial-balance-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $navigationLabel = 'Trial Balance';

    protected static ?string $title = 'Trial Balance';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return Auth::user()?->canViewLedger() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (array $filters, TrialBalance $trialBalance): array {
                $asOf = Carbon::parse($filters['as_of']['date'] ?? now());
                $rows = $trialBalance->rows($asOf);
                $totals = $trialBalance->totals($rows);

                $records = $rows->mapWithKeys(fn (array $row): array => [$row['account']->id => [
                    'code' => $row['account']->code,
                    'name' => $row['account']->name,
                    'type' => $row['account']->type->getLabel(),
                    'debit' => $row['debit'],
                    'credit' => $row['credit'],
                    'is_total' => false,
                ]])->all();

                $records['total'] = ['code' => '', 'name' => 'Total', 'type' => '', ...$totals, 'is_total' => true];

                return $records;
            })
            ->columns([
                TextColumn::make('code'),
                TextColumn::make('name')
                    ->label('Account')
                    ->weight(fn (array $record): ?FontWeight => $record['is_total'] ? FontWeight::Bold : null),
                TextColumn::make('type'),
                TextColumn::make('debit')
                    ->label('Debit (KES)')
                    ->alignEnd()
                    ->weight(fn (array $record): ?FontWeight => $record['is_total'] ? FontWeight::Bold : null)
                    ->formatStateUsing(fn (string $state, array $record): string => self::formatAmount($state, $record['is_total'])),
                TextColumn::make('credit')
                    ->label('Credit (KES)')
                    ->alignEnd()
                    ->weight(fn (array $record): ?FontWeight => $record['is_total'] ? FontWeight::Bold : null)
                    ->formatStateUsing(fn (string $state, array $record): string => self::formatAmount($state, $record['is_total'])),
            ])
            ->filters([
                Filter::make('as_of')
                    ->schema([
                        DatePicker::make('date')
                            ->label('As of')
                            ->default(now()),
                    ]),
            ])
            ->emptyStateHeading('No postings yet')
            ->paginated(false);
    }

    private static function formatAmount(string $amount, bool $alwaysShow): string
    {
        return bccomp($amount, '0', 2) === 0 && ! $alwaysShow ? '' : Number::format((float) $amount, 2);
    }
}
