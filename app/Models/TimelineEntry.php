<?php

namespace App\Models;

use App\Enums\TimelineCategory;
use App\Enums\Visibility;
use App\Support\FuzzyDate\FuzzyDate;
use App\Support\FuzzyDate\FuzzyDateCast;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A recorded moment in the family's history (`family_timeline`).
 *
 * @property string $id
 * @property string $family_id
 * @property TimelineCategory $category
 * @property string $title
 * @property string|null $description
 * @property FuzzyDate $event
 * @property string|null $place_id
 * @property Visibility $visibility
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Place|null $place
 * @property-read Collection<int, FamilyMember> $participants
 */
class TimelineEntry extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'family_timeline';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'category' => 'other',
        'visibility' => 'family',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => TimelineCategory::class,
            'visibility' => Visibility::class,
            'event' => FuzzyDateCast::class,
        ];
    }

    /** @return BelongsTo<Family, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /** @return BelongsTo<Place, $this> */
    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    /** @return BelongsToMany<FamilyMember, $this> */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(FamilyMember::class, 'timeline_participants', 'timeline_id', 'member_id')
            ->withPivot(['role', 'family_id']);
    }
}
