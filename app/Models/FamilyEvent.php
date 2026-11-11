<?php

namespace App\Models;

use App\Enums\EventType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An upcoming gathering or announcement.
 *
 * @property string $id
 * @property string $family_id
 * @property EventType $type
 * @property string $title
 * @property string|null $description
 * @property CarbonInterface $starts_at
 * @property CarbonInterface|null $ends_at
 * @property bool $all_day
 * @property string|null $location_text
 * @property string|null $place_id
 * @property bool $notify_members
 * @property string|null $timeline_id
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class FamilyEvent extends Model
{
    use HasUuids, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'other',
        'all_day' => false,
        'notify_members' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => EventType::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'all_day' => 'boolean',
            'notify_members' => 'boolean',
        ];
    }

    /** @return BelongsTo<Family, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function hasHappened(): bool
    {
        return ($this->ends_at ?? $this->starts_at)->isPast();
    }
}
