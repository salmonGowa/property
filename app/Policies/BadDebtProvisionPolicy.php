<?php

namespace App\Policies;

use App\Models\BadDebtProvision;
use App\Models\User;

class BadDebtProvisionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewLedger();
    }

    public function view(User $user, BadDebtProvision $badDebtProvision): bool
    {
        return $user->canViewLedger();
    }

    public function create(User $user): bool
    {
        return $user->canManageLedger();
    }
}
