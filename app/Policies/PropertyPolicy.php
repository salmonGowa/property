<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Property;
use App\Models\User;

class PropertyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewProperties();
    }

    public function view(User $user, Property $property): bool
    {
        return $user->canViewProperties();
    }

    public function create(User $user): bool
    {
        return $user->canManageProperties();
    }

    public function update(User $user, Property $property): bool
    {
        return $user->canManageProperties();
    }

    /**
     * Properties with units are deactivated instead of deleted.
     */
    public function delete(User $user, Property $property): bool
    {
        return $user->hasRole(UserRole::Admin) && ! $property->units()->exists();
    }
}
