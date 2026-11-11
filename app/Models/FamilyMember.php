<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\LivingStatus;
use App\Enums\Visibility;
use App\Support\FuzzyDate\FuzzyDate;
use App\Support\FuzzyDate\FuzzyDateCast;
use Database\Factories\FamilyMemberFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One person in a family tree. Each person is exactly one row; relatives are linked through
 * `family_relationships`, never copied (docs/architecture/03-relationship-modelling.md).
 *
 * @property string $id
 * @property string $family_id
 * @property string $full_name
 * @property string|null $given_names
 * @property string|null $surname
 * @property string|null $maiden_name
 * @property string|null $nickname
 * @property Gender|null $gender
 * @property FuzzyDate $birth
 * @property string|null $birth_place_id
 * @property LivingStatus $living_status
 * @property FuzzyDate $death
 * @property string|null $death_place_id
 * @property string|null $hometown_place_id
 * @property string|null $occupation
 * @property string|null $education
 * @property string|null $nationality
 * @property string|null $biography
 * @property string|null $notes
 * @property string|null $profile_photo_id
 * @property Visibility $visibility
 * @property bool $is_placeholder
 * @property int|null $generation
 * @property int $version
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Place|null $birthPlace
 * @property-read Place|null $deathPlace
 * @property-read Place|null $hometown
 */
class FamilyMember extends Model
{
    /** @use HasFactory<FamilyMemberFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** Someone of unknown status born longer ago than this is no longer presumed living. */
    public const PRESUMED_LIVING_YEARS = 110;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'living_status' => 'unknown',
        'visibility' => 'family',
        'is_placeholder' => false,
        'version' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'living_status' => LivingStatus::class,
            'visibility' => Visibility::class,
            'birth' => FuzzyDateCast::class,
            'death' => FuzzyDateCast::class,
            'is_placeholder' => 'boolean',
            'generation' => 'integer',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Removing or restoring a person changes the shape of the tree.
        static::deleted(fn (FamilyMember $member) => Family::bumpGraphVersion($member->family_id));
        static::restored(fn (FamilyMember $member) => Family::bumpGraphVersion($member->family_id));
        static::created(fn (FamilyMember $member) => Family::bumpGraphVersion($member->family_id));
    }

    /** @return BelongsTo<Family, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /** @return BelongsTo<Place, $this> */
    public function birthPlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'birth_place_id');
    }

    /** @return BelongsTo<Place, $this> */
    public function deathPlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'death_place_id');
    }

    /** @return BelongsTo<Place, $this> */
    public function hometown(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'hometown_place_id');
    }

    /**
     * Living-person privacy: a person is treated as living when marked living, or when their
     * status is unknown and they were born less than 110 years ago (or have no birth date).
     */
    public function isPresumedLiving(): bool
    {
        if ($this->living_status === LivingStatus::Living) {
            return true;
        }

        if ($this->living_status === LivingStatus::Deceased) {
            return false;
        }

        $year = $this->birth->yearValue();

        return $year === null || $year > now()->year - self::PRESUMED_LIVING_YEARS;
    }

    /**
     * @param  Builder<FamilyMember>  $query
     */
    public function scopeInFamily(Builder $query, Family|string $family): void
    {
        $query->where($this->qualifyColumn('family_id'), $family instanceof Family ? $family->id : $family);
    }
}
