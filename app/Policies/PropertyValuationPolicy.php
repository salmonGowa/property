<?php

namespace App\Policies;

use App\Models\PropertyValuation;
use App\Models\User;

/**
 * Valuations drive loan-to-value covenants, so finance and property managers record them.
 */
class PropertyValuationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewProperties();
    }

    public function view(User $user, PropertyValuation $propertyValuation): bool
    {
        return $user->canViewProperties();
    }

    public function create(User $user): bool
    {
        return $user->canManageLedger() || $user->canManageProperties();
    }

    public function update(User $user, PropertyValuation $propertyValuation): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, PropertyValuation $propertyValuation): bool
    {
        return $this->create($user);
    }
}
