<?php

namespace App\Policies;

use App\Models\Lender;
use App\Models\User;

class LenderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewLedger();
    }

    public function view(User $user, Lender $lender): bool
    {
        return $user->canViewLedger();
    }

    public function create(User $user): bool
    {
        return $user->canManageLedger();
    }

    public function update(User $user, Lender $lender): bool
    {
        return $user->canManageLedger();
    }

    public function delete(User $user, Lender $lender): bool
    {
        return $user->canManageLedger() && ! $lender->loans()->exists();
    }
}
