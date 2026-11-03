<?php

namespace App\Policies;

use App\Models\CreditNote;
use App\Models\User;

/**
 * Credit notes reduce revenue, so only finance managers and administrators issue them.
 * They are never edited or deleted.
 */
class CreditNotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewBilling();
    }

    public function view(User $user, CreditNote $creditNote): bool
    {
        return $user->canViewBilling();
    }

    public function create(User $user): bool
    {
        return $user->canManageLedger();
    }
}
