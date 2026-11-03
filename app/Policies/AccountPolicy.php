<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;

class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewLedger();
    }

    public function view(User $user, Account $account): bool
    {
        return $user->canViewLedger();
    }

    public function create(User $user): bool
    {
        return $user->canManageLedger();
    }

    public function update(User $user, Account $account): bool
    {
        return $user->canManageLedger();
    }

    /**
     * Only accounts that were never used can be deleted; used accounts are deactivated instead.
     */
    public function delete(User $user, Account $account): bool
    {
        return $user->canManageLedger()
            && ! $account->lines()->exists()
            && ! $account->children()->exists();
    }
}
