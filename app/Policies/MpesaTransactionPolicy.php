<?php

namespace App\Policies;

use App\Enums\MpesaTransactionStatus;
use App\Enums\UserRole;
use App\Models\MpesaTransaction;
use App\Models\User;

class MpesaTransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canManageBilling() || $user->hasRole(UserRole::Auditor);
    }

    public function view(User $user, MpesaTransaction $mpesaTransaction): bool
    {
        return $this->viewAny($user);
    }

    public function match(User $user, MpesaTransaction $mpesaTransaction): bool
    {
        return $user->canManageBilling() && $mpesaTransaction->status !== MpesaTransactionStatus::Matched;
    }

    public function ignore(User $user, MpesaTransaction $mpesaTransaction): bool
    {
        return $user->canManageBilling() && $mpesaTransaction->status === MpesaTransactionStatus::Unmatched;
    }
}
