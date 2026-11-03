<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListPurchaseOrders extends ListRecords
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New purchase order'),
        ];
    }

    public function getTabs(): array
    {
        $status = fn (PurchaseOrderStatus $status): \Closure => fn (Builder $query) => $query->where('status', $status);

        return [
            'mine' => Tab::make('Awaiting my approval')
                ->badge(PurchaseOrderResource::awaitingApprovalBy(PurchaseOrder::query(), Auth::user())->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => PurchaseOrderResource::awaitingApprovalBy($query, Auth::user())),
            'open' => Tab::make('Approved & open')->modifyQueryUsing($status(PurchaseOrderStatus::Approved)),
            'pending' => Tab::make('Awaiting approval')->modifyQueryUsing($status(PurchaseOrderStatus::PendingApproval)),
            'draft' => Tab::make('Drafts')->modifyQueryUsing($status(PurchaseOrderStatus::Draft)),
            'closed' => Tab::make('Closed')->modifyQueryUsing($status(PurchaseOrderStatus::Closed)),
            'cancelled' => Tab::make('Cancelled')->modifyQueryUsing($status(PurchaseOrderStatus::Cancelled)),
            'all' => Tab::make('All'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return PurchaseOrderResource::awaitingApprovalBy(PurchaseOrder::query(), Auth::user())->exists() ? 'mine' : 'open';
    }
}
