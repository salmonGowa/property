<?php

namespace App\Filament\Widgets;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\UnitStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Unit;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;

class PortfolioStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return Auth::user()?->canViewBilling() ?? false;
    }

    protected function getStats(): array
    {
        $units = Unit::query()->count();
        $occupied = Unit::query()->where('status', UnitStatus::Occupied)->count();
        $monthStart = today()->startOfMonth();

        $billed = (float) Invoice::query()->where('status', '!=', InvoiceStatus::Void)->whereBetween('issue_date', [$monthStart, today()])->sum('total');
        $collected = (float) Payment::query()->where('status', PaymentStatus::Posted)->whereBetween('received_on', [$monthStart, today()])->sum('amount');
        $outstanding = Invoice::query()->outstanding()->get(['total', 'amount_paid', 'status', 'due_date']);
        $overdue = $outstanding->filter(fn (Invoice $invoice): bool => $invoice->isOverdue());

        return [
            Stat::make('Occupancy', $units > 0 ? round($occupied / $units * 100).'%' : '-')
                ->description("{$occupied} of {$units} units let")
                ->descriptionIcon(Heroicon::OutlinedHomeModern),
            Stat::make('Billed this month', 'KES '.Number::abbreviate($billed, 1))
                ->description('Since '.$monthStart->format('j M'))
                ->descriptionIcon(Heroicon::OutlinedDocumentCurrencyDollar),
            Stat::make('Collected this month', 'KES '.Number::abbreviate($collected, 1))
                ->description($billed > 0 ? round($collected / $billed * 100).'% of billed' : 'Nothing billed yet')
                ->descriptionIcon(Heroicon::OutlinedBanknotes),
            Stat::make('Outstanding', 'KES '.Number::abbreviate($outstanding->sum(fn (Invoice $invoice): float => (float) $invoice->balanceDue()), 1))
                ->description($overdue->isEmpty() ? 'Nothing overdue' : 'KES '.Number::format($overdue->sum(fn (Invoice $invoice): float => (float) $invoice->balanceDue()), 0).' overdue')
                ->descriptionIcon($overdue->isEmpty() ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedExclamationTriangle)
                ->color($overdue->isEmpty() ? 'success' : 'danger'),
        ];
    }
}
