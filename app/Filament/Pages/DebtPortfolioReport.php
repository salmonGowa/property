<?php

namespace App\Filament\Pages;

use App\Enums\CovenantMetric;
use App\Filament\Resources\Loans\LoanResource;
use App\Filament\Widgets\DebtStats;
use App\Filament\Widgets\MaturityProfile;
use App\Models\Loan;
use App\Models\LoanCovenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use UnitEnum;

class DebtPortfolioReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.arrears-aging-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Debt & Financing';

    protected static ?string $navigationLabel = 'Debt Portfolio';

    protected static ?string $title = 'Debt Portfolio';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return Auth::user()?->canViewLedger() ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Every active facility with its covenants measured live as at '.today()->format('j M Y').'.';
    }

    protected function getHeaderWidgets(): array
    {
        return [DebtStats::class, MaturityProfile::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function table(Table $table): Table
    {
        $covenantColumn = fn (CovenantMetric $metric): TextColumn => TextColumn::make($metric->value)
            ->label($metric->shortLabel())
            ->state(function (Loan $record) use ($metric): ?string {
                $covenant = $record->covenants->first(fn (LoanCovenant $covenant): bool => $covenant->metric === $metric && $covenant->is_active);

                if ($covenant === null) {
                    return null;
                }

                $result = LoanResource::measureNow($covenant);

                return $metric->format($result['actual']).' vs '.$metric->format($covenant->threshold);
            })
            ->color(function (Loan $record) use ($metric): ?string {
                $covenant = $record->covenants->first(fn (LoanCovenant $covenant): bool => $covenant->metric === $metric && $covenant->is_active);

                return match ($covenant ? LoanResource::measureNow($covenant)['passed'] : null) {
                    true => 'success',
                    false => 'danger',
                    default => null,
                };
            })
            ->placeholder('-')
            ->alignEnd();

        return $table
            ->query(Loan::query()->active()->with(['lender', 'nextInstalment', 'covenants']))
            ->defaultSort('maturity_date')
            ->columns([
                TextColumn::make('name')->weight('semibold')->description(fn (Loan $record): string => "{$record->reference} · {$record->lender->name}"),
                TextColumn::make('outstanding')->label('Principal owed')->state(fn (Loan $record): string => $record->outstandingPrincipal())->money('KES')->alignEnd(),
                TextColumn::make('interest_rate')->label('Rate')->formatStateUsing(fn (Loan $record): string => number_format((float) $record->interest_rate, 2).'%')->alignEnd(),
                TextColumn::make('nextInstalment.due_date')
                    ->label('Next payment')
                    ->date()
                    ->description(fn (Loan $record): ?string => $record->nextInstalment ? 'KES '.Number::format((float) $record->nextInstalment->total, 2) : null)
                    ->color(fn (Loan $record): ?string => $record->nextInstalment?->isOverdue() ? 'danger' : null),
                TextColumn::make('maturity_date')->label('Maturity')->date()->sortable(),
                ...array_map($covenantColumn, CovenantMetric::cases()),
            ])
            ->recordActions([
                Action::make('open')->icon(Heroicon::OutlinedArrowRight)->url(fn (Loan $record): string => LoanResource::getUrl('view', ['record' => $record])),
            ])
            ->emptyStateHeading('No active loans')
            ->paginated(false);
    }
}
