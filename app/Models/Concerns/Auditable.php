<?php

namespace App\Models\Concerns;

use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Records create, update and delete events for the model in the audit chain.
 *
 * Models may declare a `$auditExclude` property listing attributes whose values are masked.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            $model->writeAudit('created', null, $model->auditableValues($model->getAttributes()));
        });

        static::updated(function (Model $model): void {
            $changes = $model->auditableValues($model->getChanges());

            if ($changes === []) {
                return;
            }

            $original = array_intersect_key($model->getRawOriginal(), $changes);

            $model->writeAudit('updated', $model->auditableValues($original), $changes);
        });

        static::deleted(function (Model $model): void {
            $model->writeAudit('deleted', $model->auditableValues($model->getRawOriginal()), null);
        });
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    protected function writeAudit(string $action, ?array $oldValues, ?array $newValues): void
    {
        $event = Str::snake(class_basename($this)).'.'.$action;

        app(AuditLogger::class)->record($event, $this, $oldValues, $newValues);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function auditableValues(array $values): array
    {
        $values = array_diff_key($values, array_flip(['created_at', 'updated_at']));

        foreach (array_intersect_key($values, array_flip($this->auditExclude ?? [])) as $key => $value) {
            $values[$key] = '[redacted]';
        }

        return $values;
    }
}
