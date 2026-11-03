<?php

namespace App\Policies;

use App\Enums\LeaseStatus;
use App\Enums\UserRole;
use App\Models\Lease;
use App\Models\User;

class LeasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewProperties();
    }

    public function view(User $user, Lease $lease): bool
    {
        return $user->canViewProperties();
    }

    public function create(User $user): bool
    {
        return $user->canManageProperties();
    }

    /**
     * Terms are editable only while the lease is a draft; after that they drive billing.
     */
    public function update(User $user, Lease $lease): bool
    {
        return $user->canManageProperties() && $lease->status === LeaseStatus::Draft;
    }

    public function delete(User $user, Lease $lease): bool
    {
        return $user->canManageProperties() && $lease->status === LeaseStatus::Draft;
    }

    public function activate(User $user, Lease $lease): bool
    {
        return $user->canManageProperties() && $lease->status === LeaseStatus::Draft;
    }

    public function terminate(User $user, Lease $lease): bool
    {
        return $user->canManageProperties() && $lease->status === LeaseStatus::Active;
    }

    public function renew(User $user, Lease $lease): bool
    {
        return $user->canManageProperties()
            && $lease->status === LeaseStatus::Active
            && ! $lease->renewal()->exists();
    }

    /**
     * Penalty arrangements may change during a lease, unlike its billing terms.
     */
    public function changePenaltyRule(User $user, Lease $lease): bool
    {
        return $user->hasRole(UserRole::Admin, UserRole::FinanceManager, UserRole::PropertyManager)
            && $lease->status !== LeaseStatus::Terminated;
    }

    /**
     * Refunds pay money out, so deposit settlement is a finance decision.
     */
    public function settleDeposit(User $user, Lease $lease): bool
    {
        return $user->canManageLedger() && $lease->status === LeaseStatus::Terminated;
    }
}
