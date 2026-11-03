<?php

namespace Tests\Feature\Services\Audit;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_each_entry_links_to_the_previous_entry_hash(): void
    {
        $logger = app(AuditLogger::class);

        $first = $logger->record('test.first');
        $second = $logger->record('test.second');

        $this->assertNull($first->previous_hash);
        $this->assertSame($first->hash, $second->previous_hash);
    }

    public function test_intact_chain_passes_verification(): void
    {
        $account = Account::factory()->create(['name' => 'Petty Cash']);
        $account->update(['name' => 'Petty Cash - Head Office']);

        $this->assertNull(app(AuditLogger::class)->findFirstBrokenLink());
        $this->artisan('audit:verify')->assertSuccessful();
    }

    public function test_forged_entry_breaks_the_chain_at_the_forgery(): void
    {
        $logger = app(AuditLogger::class);
        $logger->record('test.genuine');

        $forgedId = DB::table('audit_logs')->insertGetId([
            'event' => 'test.forged',
            'previous_hash' => str_repeat('0', 64),
            'hash' => str_repeat('f', 64),
            'created_at' => now(),
        ]);

        $this->assertSame($forgedId, $logger->findFirstBrokenLink());
        $this->artisan('audit:verify')->assertFailed();
    }

    public function test_entries_cannot_be_altered_directly_in_the_database(): void
    {
        $log = app(AuditLogger::class)->record('test.event');

        $this->expectException(QueryException::class);

        DB::table('audit_logs')->where('id', $log->id)->update(['event' => 'test.tampered']);
    }

    public function test_model_changes_record_old_and_new_values_and_the_acting_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $account = Account::factory()->create(['name' => 'Old Name']);

        $account->update(['name' => 'New Name']);

        $log = AuditLog::query()->where('event', 'account.updated')->sole();
        $this->assertSame($user->id, (int) $log->user_id);
        $this->assertSame(['name' => 'Old Name'], $log->oldValues());
        $this->assertSame(['name' => 'New Name'], $log->newValues());
    }

    public function test_password_change_is_recorded_without_the_password_hash(): void
    {
        $user = User::factory()->create();
        $user->update(['password' => 'a-new-secret-password']);

        $log = AuditLog::query()->where('event', 'user.updated')->sole();

        $this->assertSame(['password' => '[redacted]'], $log->newValues());
        $this->assertSame(['password' => '[redacted]'], $log->oldValues());
    }
}
