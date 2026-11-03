<?php

namespace App\Filament\Widgets;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Auth;

/**
 * Billed vs collected per month. Colours are categorical slots 1 and 2 of the data-viz
 * reference palette (dark-surface steps), validated for CVD separation and for 3:1
 * contrast on both the light and dark panel surfaces.
 */
class CollectionsChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Billed vs collected';

    protected ?string $description = 'Last 6 months, KES';

    protected ?string $maxHeight = '280px';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Auth::user()?->canViewBilling() ?? false;
    }

    protected function getData(): array
    {
        $months = collect(range(5, 0))->map(fn (int $ago) => today()->startOfMonth()->subMonthsNoOverflow($ago));

        $billed = $months->map(fn ($month): float => (float) Invoice::query()
            ->where('status', '!=', InvoiceStatus::Void)
            ->whereBetween('issue_date', [$month, $month->copy()->endOfMonth()])
            ->sum('total'));

        $collected = $months->map(fn ($month): float => (float) Payment::query()
            ->where('status', PaymentStatus::Posted)
            ->whereBetween('received_on', [$month, $month->copy()->endOfMonth()])
            ->sum('amount'));

        return [
            'datasets' => [
                [
                    'label' => 'Billed',
                    'data' => $billed->all(),
                    'backgroundColor' => '#3987e5',
                    'borderRadius' => 4,
                    'maxBarThickness' => 28,
                ],
                [
                    'label' => 'Collected',
                    'data' => $collected->all(),
                    'backgroundColor' => '#d95926',
                    'borderRadius' => 4,
                    'maxBarThickness' => 28,
                ],
            ],
            'labels' => $months->map(fn ($month): string => $month->format('M Y'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: true, position: 'top', align: 'end' },
                    tooltip: {
                        callbacks: {
                            label: (context) => `${context.dataset.label}: KES ${context.parsed.y.toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false } },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(127, 127, 127, 0.15)' },
                        border: { display: false },
                        ticks: { callback: (value) => value >= 1000000 ? `${value / 1000000}M` : (value >= 1000 ? `${value / 1000}K` : value) },
                    },
                },
            }
        JS);
    }
}
