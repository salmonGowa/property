<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('audit:verify')]
#[Description('Verify that no audit log entry has been altered, removed or inserted out of order')]
class VerifyAuditChain extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AuditLogger $auditLogger): int
    {
        $brokenId = $auditLogger->findFirstBrokenLink();

        if ($brokenId !== null) {
            $this->error("Audit chain is broken at entry #{$brokenId}. Entries from this point cannot be trusted.");

            return self::FAILURE;
        }

        $this->info('Audit chain intact ('.AuditLog::query()->count().' entries verified).');

        return self::SUCCESS;
    }
}
