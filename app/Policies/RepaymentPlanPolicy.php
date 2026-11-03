<?php

namespace App\Policies;

use App\Enums\RepaymentPlanStatus;
use App\Models\RepaymentPlan;
use App\Models\User;

class RepaymentPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewBilling();
    }

    public function view(User $user, RepaymentPlan $repaymentPlan): bool
    {
        return $user->canViewBilling();
    }

    public function create(User $user): bool
    {
        return $user->canManageBilling();
    }

    public function cancel(User $user, RepaymentPlan $repaymentPlan): bool
    {
        return $user->canManageLedger() && $repaymentPlan->status === RepaymentPlanStatus::Active;
    }
}
