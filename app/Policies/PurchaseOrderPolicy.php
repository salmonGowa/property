<?php

namespace App\Policies;

use App\Enums\InvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Procurement\PurchaseOrderService;

class PurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewProcurement();
    }

    public function view(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->canViewProcurement();
    }

    public function create(User $user): bool
    {
        return $user->canRaisePurchaseOrders();
    }

    /**
     * Drafts are edited by whoever raised them, or by finance staff.
     */
    public function update(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $purchaseOrder->status === PurchaseOrderStatus::Draft
            && $user->canRaisePurchaseOrders()
            && ($purchaseOrder->requested_by === $user->id || $user->canManagePayables());
    }

    public function submit(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->update($user, $purchaseOrder);
    }

    /**
     * Approve or reject: someone other than the requester whose limit covers the total.
     */
    public function approve(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return app(PurchaseOrderService::class)->approvalBlocker($purchaseOrder, $user) === null;
    }

    public function cancel(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return in_array($purchaseOrder->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::PendingApproval, PurchaseOrderStatus::Approved], true)
            && ! $purchaseOrder->bills()->where('status', '!=', InvoiceStatus::Void)->exists()
            && ($purchaseOrder->requested_by === $user->id || $user->canManagePayables());
    }

    public function close(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $purchaseOrder->status === PurchaseOrderStatus::Approved && $user->canManagePayables();
    }
}
