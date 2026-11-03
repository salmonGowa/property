<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('user:assign-role {email : The user\'s email address} {role=admin : One of admin, finance_manager, accountant, property_manager, warehouse_manager, gate_officer, auditor}')]
#[Description('Give a user a role and reactivate them (use after make:filament-user, or to recover a locked-out administrator)')]
class AssignUserRole extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $role = UserRole::tryFrom((string) $this->argument('role'));

        if ($role === null) {
            $this->error('Unknown role. Choose one of: '.implode(', ', array_column(UserRole::cases(), 'value')).'.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No user has that email address.');

            return self::FAILURE;
        }

        $user->update(['role' => $role, 'is_active' => true]);

        $this->info("{$user->name} is now an active {$role->getLabel()}.");

        return self::SUCCESS;
    }
}
