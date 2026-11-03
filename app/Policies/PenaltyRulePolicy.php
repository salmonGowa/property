<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\PenaltyRule;
use App\Models\User;

class PenaltyRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewBilling();
    }

    public function view(User $user, PenaltyRule $penaltyRule): bool
    {
        return $user->canViewBilling();
    }

    /**
     * Penalty policy is set by management: administrators, finance and property managers.
     */
    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::Admin, UserRole::FinanceManager, UserRole::PropertyManager);
    }

    public function update(User $user, PenaltyRule $penaltyRule): bool
    {
        return $this->create($user);
    }

    /**
     * Rules that have charged penalties stay for the record; deactivate them instead.
     */
    public function delete(User $user, PenaltyRule $penaltyRule): bool
    {
        return $this->create($user) && ! $penaltyRule->charges()->exists();
    }
}
