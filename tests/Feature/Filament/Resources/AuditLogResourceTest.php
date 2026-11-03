<?php

namespace Tests\Feature\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\AuditLogs\Pages\ManageAuditLogs;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuditLogResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_auditor_sees_entries_and_verifies_the_chain(): void
    {
        $log = app(AuditLogger::class)->record('test.event');
        $this->actingAs(User::factory()->withRole(UserRole::Auditor)->create());

        Livewire::test(ManageAuditLogs::class)
            ->assertCanSeeTableRecords([$log])
            ->callAction('verifyChain')
            ->assertNotified('Audit trail intact');
    }

    public function test_accountant_cannot_open_the_audit_trail(): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create())
            ->get('/admin/audit-logs')
            ->assertForbidden();
    }
}
