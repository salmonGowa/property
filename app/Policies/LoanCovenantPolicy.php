<?php

namespace App\Policies;

use App\Models\LoanCovenant;
use App\Models\User;

class LoanCovenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewLedger();
    }

    public function view(User $user, LoanCovenant $loanCovenant): bool
    {
        return $user->canViewLedger();
    }

    public function create(User $user): bool
    {
        return $user->canManageLedger();
    }

    public function update(User $user, LoanCovenant $loanCovenant): bool
    {
        return $user->canManageLedger();
    }

    /**
     * Covenants that have been tested stay for the record; deactivate them instead.
     */
    public function delete(User $user, LoanCovenant $loanCovenant): bool
    {
        return $user->canManageLedger() && ! $loanCovenant->tests()->exists();
    }
}
