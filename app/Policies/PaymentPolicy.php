<?php

namespace App\Policies;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewBilling();
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->canViewBilling();
    }

    public function create(User $user): bool
    {
        return $user->canManageBilling();
    }

    public function reverse(User $user, Payment $payment): bool
    {
        return $user->canManageLedger() && $payment->status === PaymentStatus::Posted;
    }
}
