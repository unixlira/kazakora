<?php

namespace App\Support\Rbac;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Attach to a model to log every create/update/delete into `audit_logs`,
 * as long as the acting user is staff (admin/manager/subscriber) — customer
 * self-service actions (e.g. placing an order) are not admin activity and
 * are intentionally not logged here.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => self::recordAudit(AuditLog::ACTION_CREATE, $model, null, self::redact($model, $model->getAttributes())));

        static::updated(function (Model $model) {
            $changedKeys = array_keys($model->getChanges());
            $original = array_intersect_key($model->getOriginal(), array_flip($changedKeys));
            $changes = array_intersect_key($model->getAttributes(), array_flip($changedKeys));

            if ($changes === []) {
                return;
            }

            self::recordAudit(AuditLog::ACTION_UPDATE, $model, self::redact($model, $original), self::redact($model, $changes));
        });

        static::deleted(fn (Model $model) => self::recordAudit(AuditLog::ACTION_DELETE, $model, self::redact($model, $model->getAttributes()), null));
    }

    /**
     * Replace values for any attribute the model lists in a static
     * `$auditExcept` property (e.g. passwords) before they're written to the
     * audit trail — we want to know *that* they changed, never the value.
     */
    private static function redact(Model $model, array $attributes): array
    {
        $except = property_exists($model, 'auditExcept') ? $model::$auditExcept : [];

        foreach ($except as $key) {
            if (array_key_exists($key, $attributes)) {
                $attributes[$key] = '••••••••';
            }
        }

        return $attributes;
    }

    private static function recordAudit(string $action, Model $model, ?array $old, ?array $new): void
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->isStaff()) {
            return;
        }

        AuditLog::query()->create([
            'user_id' => $user->id,
            'action' => $action,
            'entity' => class_basename($model),
            'entity_id' => $model->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'created_at' => now(),
        ]);
    }
}
