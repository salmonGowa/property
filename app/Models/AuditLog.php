<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * One link in the tamper-evident audit chain. Each row's hash covers its own
 * contents plus the previous row's hash, so altering or removing any row breaks
 * every hash after it. Rows are written only through AuditLogger.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Audit log entries cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function oldValues(): ?array
    {
        return $this->decodeValues('old_values');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function newValues(): ?array
    {
        return $this->decodeValues('new_values');
    }

    /**
     * Compute the hash for a row from its raw stored values.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function computeHash(array $attributes): string
    {
        $fields = ['previous_hash', 'user_id', 'event', 'auditable_type', 'auditable_id', 'old_values', 'new_values', 'ip_address', 'user_agent', 'created_at'];

        $payload = array_map(
            fn (string $field): ?string => isset($attributes[$field]) ? (string) $attributes[$field] : null,
            $fields,
        );

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeValues(string $column): ?array
    {
        $raw = $this->getRawOriginal($column);

        return $raw === null ? null : json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }
}
