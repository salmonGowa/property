<?php

namespace App\Filament\Widgets;

use App\Enums\WorkOrderPriority;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\SupplierBills\SupplierBillResource;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\PurchaseOrder;
use App\Models\SupplierBill;
use App\Models\WorkOrder;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;

class MaintenanceStats extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return Auth::user()?->canViewMaintenance() || Auth::user()?->canViewProcurement();
    }

    protected function getStats(): array
    {
        $open = WorkOrder::query()->unfinished()->count();
        $overdue = WorkOrder::query()->overdue()->count();
        $urgent = WorkOrder::query()->unfinished()->where('priority', WorkOrderPriority::Emergency)->count();
        $awaitingMe = PurchaseOrderResource::awaitingApprovalBy(PurchaseOrder::query(), Auth::user());
        $dueSoon = SupplierBill::query()->outstanding()->whereDate('due_date', '<=', today()->addDays(7))->get();

        return [
            Stat::make('Open work orders', (string) $open)
                ->description($urgent > 0 ? "{$urgent} emergency" : 'No emergencies')
                ->descriptionIcon(Heroicon::OutlinedWrenchScrewdriver)
                ->color($urgent > 0 ? 'danger' : null)
                ->url(WorkOrderResource::getUrl()),
            Stat::make('Overdue work orders', (string) $overdue)
                ->description($overdue > 0 ? 'Past their target date' : 'All on time')
                ->descriptionIcon($overdue > 0 ? Heroicon::OutlinedClock : Heroicon::OutlinedCheckCircle)
                ->color($overdue > 0 ? 'danger' : 'success')
                ->url(WorkOrderResource::getUrl('index', ['tab' => 'overdue'])),
            Stat::make('Awaiting your approval', (string) $awaitingMe->count())
                ->description('KES '.Number::format((float) $awaitingMe->sum('total'), 0).' in purchase orders')
                ->descriptionIcon(Heroicon::OutlinedCheckBadge)
                ->color($awaitingMe->count() > 0 ? 'warning' : null)
                ->url(PurchaseOrderResource::getUrl('index', ['tab' => 'mine'])),
            Stat::make('Supplier bills due in 7 days', 'KES '.Number::abbreviate($dueSoon->sum(fn (SupplierBill $bill): float => (float) $bill->balanceDue()), 1))
                ->description($dueSoon->filter->isOverdue()->count().' already overdue')
                ->descriptionIcon(Heroicon::OutlinedDocumentText)
                ->url(SupplierBillResource::getUrl('index', ['tab' => 'due_soon'])),
        ];
    }
}
