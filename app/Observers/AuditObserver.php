<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\AuditLog;
use App\Support\CurrentUnitContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class AuditObserver
{
    /** What a hidden attribute (a password hash, a remember token) is logged as. */
    public const REDACTED = '[redacted]';

    /**
     * Handle the Model "created" event.
     */
    public function created(Model $model): void
    {
        $this->log($model, 'created', null, $this->redact($model, $model->getAttributes()));
    }

    /**
     * Handle the Model "updated" event.
     */
    public function updated(Model $model): void
    {
        $dirty = $model->getDirty();
        $old = [];
        $new = [];

        foreach ($dirty as $key => $value) {
            if (in_array($key, ['updated_at', 'created_at', 'record_version'])) {
                continue;
            }
            $old[$key] = $model->getOriginal($key);
            $new[$key] = $value;
        }

        if (count($new) > 0) {
            $this->log($model, 'updated', $this->redact($model, $old), $this->redact($model, $new));
        }
    }

    /**
     * Handle the Model "deleted" event.
     */
    public function deleted(Model $model): void
    {
        $this->log($model, 'deleted', $this->redact($model, $model->getAttributes()), null);
    }

    /**
     * Handle the Model "restored" event (soft-delete undone).
     */
    public function restored(Model $model): void
    {
        $this->log($model, 'restored', null, $this->redact($model, $model->getAttributes()));
    }

    /**
     * Never write a model's hidden attributes (password hashes, tokens) to the log:
     * the key stays, so a password change is still on record, but its value doesn't.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function redact(Model $model, array $values): array
    {
        foreach ($model->getHidden() as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = self::REDACTED;
            }
        }

        return $values;
    }

    /**
     * Persist the audit log entry.
     */
    private function log(Model $model, string $action, ?array $old, ?array $new): void
    {
        if ($model instanceof AuditLog) {
            return;
        }

        $context = app(CurrentUnitContext::class);

        AuditLog::create([
            'id' => (string) Str::uuid(),
            'user_id' => auth()->id(),
            'operating_unit_id' => $context->id(),
            'table_name' => $model->getTable(),
            'record_id' => $model->getKey(),
            'action' => $action,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => request()->ip(),
        ]);
    }
}
