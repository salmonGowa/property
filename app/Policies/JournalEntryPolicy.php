<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\JournalEntry;
use App\Models\User;

/**
 * Journal entries are never updated or deleted, so those abilities are not granted.
 */
class JournalEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewLedger();
    }

    public function view(User $user, JournalEntry $journalEntry): bool
    {
        return $user->canViewLedger();
    }

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::Admin, UserRole::FinanceManager, UserRole::Accountant);
    }

    public function reverse(User $user, JournalEntry $journalEntry): bool
    {
        return $user->canManageLedger()
            && $journalEntry->reverses_entry_id === null
            && ! $journalEntry->reversal()->exists();
    }
}
