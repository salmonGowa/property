<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AuditLogger
{
    /**
     * Append an event to the audit chain.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function record(string $event, ?Model $subject = null, ?array $oldValues = null, ?array $newValues = null): AuditLog
    {
        return DB::transaction(function () use ($event, $subject, $oldValues, $newValues): AuditLog {
            $previous = AuditLog::query()->latest('id')->lockForUpdate()->first();

            $attributes = [
                'previous_hash' => $previous?->hash,
                'user_id' => Auth::id(),
                'event' => $event,
                'auditable_type' => $subject?->getMorphClass(),
                'auditable_id' => $subject?->getKey(),
                'old_values' => $this->encode($oldValues),
                'new_values' => $this->encode($newValues),
                'ip_address' => app()->runningInConsole() ? null : request()->ip(),
                'user_agent' => app()->runningInConsole() ? null : request()->userAgent(),
                'created_at' => now()->format('Y-m-d H:i:s'),
            ];

            $log = new AuditLog;
            $log->forceFill([...$attributes, 'hash' => AuditLog::computeHash($attributes)]);
            $log->save();

            return $log;
        });
    }

    /**
     * Walk the chain in order and return the id of the first row that fails verification.
     */
    public function findFirstBrokenLink(): ?int
    {
        $expectedPreviousHash = null;
        $brokenId = null;

        AuditLog::query()->orderBy('id')->chunk(500, function ($logs) use (&$expectedPreviousHash, &$brokenId): bool {
            foreach ($logs as $log) {
                $attributes = $log->getRawOriginal();

                if ($attributes['previous_hash'] !== $expectedPreviousHash || $attributes['hash'] !== AuditLog::computeHash($attributes)) {
                    $brokenId = $log->id;

                    return false;
                }

                $expectedPreviousHash = $attributes['hash'];
            }

            return true;
        });

        return $brokenId;
    }

    /**
     * @param  array<string, mixed>|null  $values
     */
    private function encode(?array $values): ?string
    {
        if ($values === null) {
            return null;
        }

        return json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
