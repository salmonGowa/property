<?php

namespace App\Policies;

use App\Enums\PaymentStatus;
use App\Models\SupplierPayment;
use App\Models\User;

/**
 * Money only leaves the company on the say-so of a finance manager or administrator.
 */
class SupplierPaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewProcurement();
    }

    public function view(User $user, SupplierPayment $supplierPayment): bool
    {
        return $user->canViewProcurement();
    }

    public function create(User $user): bool
    {
        return $user->canManageLedger();
    }

    public function reverse(User $user, SupplierPayment $supplierPayment): bool
    {
        return $user->canManageLedger() && $supplierPayment->status === PaymentStatus::Posted;
    }
}
