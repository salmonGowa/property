<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Unit;
use App\Models\User;

class UnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewProperties();
    }

    public function view(User $user, Unit $unit): bool
    {
        return $user->canViewProperties();
    }

    public function create(User $user): bool
    {
        return $user->canManageProperties();
    }

    public function update(User $user, Unit $unit): bool
    {
        return $user->canManageProperties();
    }

    public function delete(User $user, Unit $unit): bool
    {
        return $user->hasRole(UserRole::Admin) && ! $unit->leases()->exists();
    }
}
