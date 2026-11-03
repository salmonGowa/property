<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;

/**
 * The audit log is read-only for everyone.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::Admin, UserRole::Auditor);
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $user->hasRole(UserRole::Admin, UserRole::Auditor);
    }
}
