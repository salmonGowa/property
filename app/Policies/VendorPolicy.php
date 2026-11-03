<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vendor;

/**
 * Supplier records hold the bank details payments go to, so only finance staff maintain them.
 */
class VendorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewProcurement();
    }

    public function view(User $user, Vendor $vendor): bool
    {
        return $user->canViewProcurement();
    }

    public function create(User $user): bool
    {
        return $user->canManagePayables();
    }

    public function update(User $user, Vendor $vendor): bool
    {
        return $user->canManagePayables();
    }

    /**
     * Suppliers with any history stay on record; deactivate them instead.
     */
    public function delete(User $user, Vendor $vendor): bool
    {
        return $user->canManagePayables()
            && ! $vendor->purchaseOrders()->exists()
            && ! $vendor->bills()->exists()
            && ! $vendor->workOrders()->exists();
    }
}
