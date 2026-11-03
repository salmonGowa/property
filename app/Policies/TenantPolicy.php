<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;

class TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewProperties();
    }

    public function view(User $user, Tenant $tenant): bool
    {
        return $user->canViewProperties();
    }

    public function create(User $user): bool
    {
        return $user->canManageProperties();
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $user->canManageProperties();
    }

    /**
     * Only tenants that never had a lease or invoice can be deleted; others are deactivated.
     */
    public function delete(User $user, Tenant $tenant): bool
    {
        return $user->hasRole(UserRole::Admin)
            && ! $tenant->leases()->exists()
            && ! $tenant->invoices()->exists();
    }

    /**
     * KYC documents contain personal data, so only those who manage tenants may open them.
     */
    public function viewDocuments(User $user, Tenant $tenant): bool
    {
        return $user->hasRole(UserRole::Admin, UserRole::PropertyManager, UserRole::Auditor);
    }

    public function manageDocuments(User $user, Tenant $tenant): bool
    {
        return $user->canManageProperties();
    }
}
