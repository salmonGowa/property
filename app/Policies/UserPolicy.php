<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Users are deactivated rather than deleted so their audit history keeps its author.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::Admin);
    }

    public function view(User $user, User $model): bool
    {
        return $user->hasRole(UserRole::Admin);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::Admin);
    }

    public function update(User $user, User $model): bool
    {
        return $user->hasRole(UserRole::Admin);
    }
}
