<?php

namespace App\Policies;

use App\Models\MaintenanceSchedule;
use App\Models\User;

class MaintenanceSchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewMaintenance();
    }

    public function view(User $user, MaintenanceSchedule $maintenanceSchedule): bool
    {
        return $user->canViewMaintenance();
    }

    public function create(User $user): bool
    {
        return $user->canManageMaintenance();
    }

    public function update(User $user, MaintenanceSchedule $maintenanceSchedule): bool
    {
        return $user->canManageMaintenance();
    }

    /**
     * Schedules that have raised work stay for the record; deactivate them instead.
     */
    public function delete(User $user, MaintenanceSchedule $maintenanceSchedule): bool
    {
        return $user->canManageMaintenance() && ! $maintenanceSchedule->workOrders()->exists();
    }
}
