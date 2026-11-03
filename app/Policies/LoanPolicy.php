<?php

namespace App\Policies;

use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Models\User;

/**
 * Borrowings are managed by finance managers and administrators; anyone with ledger access can view them.
 */
class LoanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewLedger();
    }

    public function view(User $user, Loan $loan): bool
    {
        return $user->canViewLedger();
    }

    public function create(User $user): bool
    {
        return $user->canManageLedger();
    }

    public function update(User $user, Loan $loan): bool
    {
        return $user->canManageLedger() && $loan->status !== LoanStatus::Closed;
    }

    /**
     * Draw down, repay, prepay or reprice.
     */
    public function transact(User $user, Loan $loan): bool
    {
        return $this->update($user, $loan);
    }
}
