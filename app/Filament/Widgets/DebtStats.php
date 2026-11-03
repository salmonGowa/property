<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\DebtPortfolioReport;
use App\Models\Loan;
use App\Models\LoanCovenant;
use App\Models\LoanInstalment;
use App\Services\Debt\CovenantService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;

class DebtStats extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    public static function canView(): bool
    {
        return (Auth::user()?->canViewLedger() ?? false) && Loan::query()->active()->exists();
    }

    protected function getStats(): array
    {
        $loans = Loan::query()->active()->get();
        $balances = $loans->mapWithKeys(fn (Loan $loan): array => [$loan->id => (float) $loan->outstandingPrincipal()]);
        $total = $balances->sum();
        $weightedRate = $total > 0 ? $loans->sum(fn (Loan $loan): float => $balances[$loan->id] * (float) $loan->interest_rate) / $total : 0;
        $unpaid = fn (): Builder => LoanInstalment::query()->whereNull('loan_repayment_id')->whereHas('loan', fn (Builder $query) => $query->active());
        $nextYear = (float) $unpaid()->whereDate('due_date', '<=', today()->addYear())->sum('total');
        $next30 = (float) $unpaid()->whereDate('due_date', '<=', today()->addDays(30))->sum('total');
        $overdue = $unpaid()->whereDate('due_date', '<', today())->count();
        $covenants = LoanCovenant::query()->where('is_active', true)->whereHas('loan', fn (Builder $query) => $query->active())->with('loan')->get();
        $breaches = $covenants->filter(fn (LoanCovenant $covenant): bool => app(CovenantService::class)->measure($covenant, today())['passed'] === false)->count();

        return [
            Stat::make('Total debt', 'KES '.Number::abbreviate($total, 1))
                ->description($loans->count().' active '.str('loan')->plural($loans->count()).' · avg '.number_format($weightedRate, 2).'%')
                ->descriptionIcon(Heroicon::OutlinedScale)
                ->url(DebtPortfolioReport::getUrl()),
            Stat::make('Debt service, next 30 days', 'KES '.Number::abbreviate($next30, 1))
                ->description($overdue > 0 ? "{$overdue} instalment(s) overdue" : 'KES '.Number::abbreviate($nextYear, 1).' over 12 months')
                ->descriptionIcon($overdue > 0 ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedCalendar)
                ->color($overdue > 0 ? 'danger' : null),
            Stat::make('Covenants', $covenants->isEmpty() ? '-' : ($breaches > 0 ? "{$breaches} breached" : 'All met'))
                ->description($covenants->count().' tested live')
                ->descriptionIcon($breaches > 0 ? Heroicon::OutlinedShieldExclamation : Heroicon::OutlinedShieldCheck)
                ->color($breaches > 0 ? 'danger' : ($covenants->isEmpty() ? null : 'success'))
                ->url(DebtPortfolioReport::getUrl()),
        ];
    }
}
