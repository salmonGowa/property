<?php

namespace App\Filament\Resources\WorkOrders\Pages;

use App\Enums\WorkOrderPriority;
use App\Enums\WorkOrderStatus;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\WorkOrder;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListWorkOrders extends ListRecords
{
    protected static string $resource = WorkOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Log work order'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'open' => Tab::make('Open')
                ->badge(WorkOrder::query()->unfinished()->count() ?: null)
                ->modifyQueryUsing(fn (Builder $query) => $query->unfinished()),
            'overdue' => Tab::make('Overdue')
                ->badge(WorkOrder::query()->overdue()->count() ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->overdue()),
            'urgent' => Tab::make('Emergency & high')
                ->modifyQueryUsing(fn (Builder $query) => $query->unfinished()->whereIn('priority', [WorkOrderPriority::Emergency, WorkOrderPriority::High])),
            'unassigned' => Tab::make('Not assigned')
                ->badge(WorkOrder::query()->where('status', WorkOrderStatus::Open)->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', WorkOrderStatus::Open)),
            'completed' => Tab::make('Completed')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', WorkOrderStatus::Completed)->reorder('completed_at', 'desc')),
            'cancelled' => Tab::make('Cancelled')->modifyQueryUsing(fn (Builder $query) => $query->where('status', WorkOrderStatus::Cancelled)),
            'all' => Tab::make('All'),
        ];
    }
}
