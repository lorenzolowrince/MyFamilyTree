<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A change someone proposed that waits for a reviewer. The payload was validated with the
 * same rules as a direct edit when it was submitted, and is applied through the same Action
 * when approved.
 *
 * @property string $id
 * @property string $family_id
 * @property string $submitted_by
 * @property string $target_type member | relationship | timeline
 * @property string|null $target_id
 * @property string $action create | update | delete
 * @property array<string, mixed> $payload
 * @property int|null $base_version
 * @property string $summary
 * @property string $visibility
 * @property string $status pending | approved | rejected | withdrawn | superseded
 * @property string|null $reviewer_id
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property Carbon|null $applied_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $submitter
 * @property-read User|null $reviewer
 * @property-read Family|null $family
 */
class ChangeRequest extends Model
{
    use HasUuids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'visibility' => 'family',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'base_version' => 'integer',
            'reviewed_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Family, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id')->withTrashed();
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** `member.update`, `member.create`, `relationship.create`, `timeline.create`, `member.delete`. */
    public function kind(): string
    {
        return "{$this->target_type}.{$this->action}";
    }
}
