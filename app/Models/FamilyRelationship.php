<?php

namespace App\Models;

use App\Enums\Certainty;
use App\Enums\ParentSubtype;
use App\Enums\PartnershipStatus;
use App\Enums\PartnershipSubtype;
use App\Enums\RelationshipType;
use App\Support\FuzzyDate\FuzzyDate;
use App\Support\FuzzyDate\FuzzyDateCast;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One edge of the family graph.
 *
 * parent_child: person_a is the parent, person_b the child.
 * partnership:  unordered; stored with person_a_id < person_b_id.
 *
 * @property string $id
 * @property string $family_id
 * @property RelationshipType $type
 * @property string $person_a_id
 * @property string $person_b_id
 * @property string $subtype
 * @property PartnershipStatus|null $status
 * @property Certainty $certainty
 * @property FuzzyDate $start
 * @property FuzzyDate $end
 * @property string|null $place_id
 * @property int|null $sequence
 * @property string|null $notes
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read FamilyMember|null $personA
 * @property-read FamilyMember|null $personB
 * @property-read Place|null $place
 */
class FamilyRelationship extends Model
{
    use HasUuids, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'certainty' => 'confirmed',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => RelationshipType::class,
            'status' => PartnershipStatus::class,
            'certainty' => Certainty::class,
            'start' => FuzzyDateCast::class,
            'end' => FuzzyDateCast::class,
            'sequence' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Every change to an edge invalidates cached tree data for the family.
        static::saved(fn (FamilyRelationship $relationship) => Family::bumpGraphVersion($relationship->family_id));
        static::deleted(fn (FamilyRelationship $relationship) => Family::bumpGraphVersion($relationship->family_id));
        static::restored(fn (FamilyRelationship $relationship) => Family::bumpGraphVersion($relationship->family_id));
    }

    /** @return BelongsTo<Family, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /** @return BelongsTo<FamilyMember, $this> */
    public function personA(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class, 'person_a_id');
    }

    /** @return BelongsTo<FamilyMember, $this> */
    public function personB(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class, 'person_b_id');
    }

    /** @return BelongsTo<Place, $this> */
    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    public function isParentChild(): bool
    {
        return $this->type === RelationshipType::ParentChild;
    }

    public function isPartnership(): bool
    {
        return $this->type === RelationshipType::Partnership;
    }

    public function involves(string $memberId): bool
    {
        return $this->person_a_id === $memberId || $this->person_b_id === $memberId;
    }

    /** The person at the other end of this edge. */
    public function otherPersonId(string $memberId): string
    {
        return $this->person_a_id === $memberId ? $this->person_b_id : $this->person_a_id;
    }

    public function subtypeLabel(): string
    {
        return $this->isParentChild()
            ? (ParentSubtype::tryFrom($this->subtype)?->label() ?? $this->subtype)
            : (PartnershipSubtype::tryFrom($this->subtype)?->label() ?? $this->subtype);
    }
}
