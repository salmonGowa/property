<?php

namespace App\Policies;

use App\Enums\InvoiceStatus;
use App\Models\SupplierBill;
use App\Models\User;

/**
 * Bills are never edited or deleted once entered; they are voided instead.
 */
class SupplierBillPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewProcurement();
    }

    public function view(User $user, SupplierBill $supplierBill): bool
    {
        return $user->canViewProcurement();
    }

    public function create(User $user): bool
    {
        return $user->canManagePayables();
    }

    public function void(User $user, SupplierBill $supplierBill): bool
    {
        return $user->canManageLedger()
            && $supplierBill->status !== InvoiceStatus::Void
            && ! $supplierBill->allocations()->exists();
    }
}
