<?php

namespace App\Services\Audit;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes the two histories of a family (docs/architecture/02-database.md):
 *
 * - `audit_logs`: the security record. Who changed what, the values before and after, from
 *   which address. Append-only; never shown to ordinary members.
 * - `activity_logs`: the human-readable feed ("Siti added Ahmad bin Ali").
 *
 * Bound per request, so every row written while handling one request shares a request id.
 */
class HistoryRecorder
{
    /** Never written to the audit log: secrets, and derived or bookkeeping columns. */
    private const IGNORED = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'token_hash',
        'created_at', 'updated_at', 'created_by', 'updated_by', 'version', 'generation',
        'search_vector', 'birth_month', 'birth_day', 'graph_version',
    ];

    private ?string $requestId = null;

    private bool $muted = false;

    /** Run something without recording (bulk imports record one summary line instead). */
    public function quietly(callable $callback): mixed
    {
        $previous = $this->muted;
        $this->muted = true;

        try {
            return $callback();
        } finally {
            $this->muted = $previous;
        }
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function audit(string $action, ?string $familyId, ?string $type = null, ?string $id = null, ?array $old = null, ?array $new = null, ?User $actor = null): void
    {
        if ($this->muted) {
            return;
        }

        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        DB::table('audit_logs')->insert([
            'family_id' => $familyId,
            'user_id' => $actor !== null ? $actor->id : Auth::id(),
            'action' => $action,
            'auditable_type' => $type,
            'auditable_id' => $id,
            'old_values' => $old === null ? null : json_encode(self::clean($old), JSON_THROW_ON_ERROR),
            'new_values' => $new === null ? null : json_encode(self::clean($new), JSON_THROW_ON_ERROR),
            'ip_address' => $request?->ip(),
            'user_agent' => $request === null ? null : Str::limit((string) $request->userAgent(), 500, ''),
            'request_id' => $this->requestId ??= (string) Str::uuid7(),
            'created_at' => now(),
        ]);
    }

    public function activity(string $familyId, string $verb, string $summary, ?string $subjectType = null, ?string $subjectId = null, string $visibility = 'family', ?User $actor = null): void
    {
        if ($this->muted) {
            return;
        }

        DB::table('activity_logs')->insert([
            'id' => (string) Str::uuid7(),
            'family_id' => $familyId,
            'user_id' => $actor !== null ? $actor->id : Auth::id(),
            'verb' => $verb,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'summary' => Str::limit($summary, 500),
            'visibility' => $visibility,
            'created_at' => now(),
        ]);
    }

    /** A model that uses RecordsHistory was created, changed or removed. */
    public function model(string $event, Model&HasHistory $model): void
    {
        if ($this->muted) {
            return;
        }

        $noun = $model->historyNoun();
        $familyId = $model->historyFamilyId();

        // A soft delete arriving as an update (deleted_at set) is a removal.
        if ($event === 'updated' && $model->wasChanged('deleted_at') && $model->getAttribute('deleted_at') !== null) {
            $event = 'deleted';
        }

        $old = $new = null;

        if ($event === 'created') {
            $new = $model->getAttributes();
        } elseif ($event === 'deleted') {
            $old = $model->getRawOriginal();
        } else {
            $new = array_diff_key($model->getChanges(), array_flip(self::IGNORED));

            if ($new === []) {
                return;
            }

            $old = array_intersect_key($model->getRawOriginal(), $new);
        }

        $this->audit("{$noun}.{$event}", $familyId, $noun, (string) $model->getKey(), $old, $new);

        $summary = $model->historySummary($event);

        if ($summary !== null) {
            $this->activity($familyId, "{$noun}.{$event}", $summary, $noun, (string) $model->getKey(), $model->historyVisibility());
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function clean(array $values): array
    {
        return array_diff_key($values, array_flip(self::IGNORED));
    }
}
