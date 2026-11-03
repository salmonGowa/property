<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\FiscalPeriod;
use App\Models\User;

class FiscalPeriodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewLedger();
    }

    public function view(User $user, FiscalPeriod $fiscalPeriod): bool
    {
        return $user->canViewLedger();
    }

    /**
     * Creating periods means opening a new fiscal year.
     */
    public function create(User $user): bool
    {
        return $user->canManageLedger();
    }

    public function close(User $user, FiscalPeriod $fiscalPeriod): bool
    {
        return $user->canManageLedger() && $fiscalPeriod->isOpen();
    }

    /**
     * Reopening allows changes to reported figures, so it is reserved for administrators.
     */
    public function reopen(User $user, FiscalPeriod $fiscalPeriod): bool
    {
        return $user->hasRole(UserRole::Admin) && ! $fiscalPeriod->isOpen();
    }
}
