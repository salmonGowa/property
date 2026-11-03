<?php

namespace App\Policies;

use App\Enums\InvoiceStatus;
use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;

class WorkOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewMaintenance();
    }

    public function view(User $user, WorkOrder $workOrder): bool
    {
        return $user->canViewMaintenance();
    }

    public function create(User $user): bool
    {
        return $user->canManageMaintenance();
    }

    /**
     * Assign, start, complete or edit: property staff, while the work is unfinished.
     */
    public function update(User $user, WorkOrder $workOrder): bool
    {
        return $user->canManageMaintenance() && $workOrder->status->isUnfinished();
    }

    public function cancel(User $user, WorkOrder $workOrder): bool
    {
        return $this->update($user, $workOrder);
    }

    /**
     * Invoicing the tenant for damage is a billing task.
     */
    public function recharge(User $user, WorkOrder $workOrder): bool
    {
        return $user->canManageBilling()
            && $workOrder->status === WorkOrderStatus::Completed
            && $workOrder->tenant_chargeable
            && $workOrder->tenant_id !== null
            && ($workOrder->rechargeInvoice === null || $workOrder->rechargeInvoice->status === InvoiceStatus::Void);
    }
}
