<?php

namespace App\Models;

use Database\Factories\FamilyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A family tree (the tenant). Everything a family records hangs off this row.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $cover_photo_id
 * @property string $owner_id
 * @property string|null $root_member_id
 * @property array<string, mixed> $settings
 * @property int $graph_version
 * @property int $storage_bytes_used
 * @property int $storage_quota_bytes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'slug', 'description', 'settings'])]
class Family extends Model
{
    /** @use HasFactory<FamilyFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'settings' => '{}',
        'graph_version' => 0,
        'storage_bytes_used' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(function (Family $family): void {
            $family->storage_quota_bytes ??= (int) config('families.default_storage_quota_bytes');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'graph_version' => 'integer',
            'storage_bytes_used' => 'integer',
            'storage_quota_bytes' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<FamilyMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(FamilyMembership::class);
    }

    /** @return HasMany<FamilyMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(FamilyMember::class);
    }

    /** @return HasMany<FamilyRelationship, $this> */
    public function relationships(): HasMany
    {
        return $this->hasMany(FamilyRelationship::class);
    }

    /** @return HasMany<Place, $this> */
    public function places(): HasMany
    {
        return $this->hasMany(Place::class);
    }

    /** @return HasMany<TimelineEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(TimelineEntry::class);
    }

    /** @return HasMany<FamilyInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(FamilyInvitation::class);
    }

    /** @return HasMany<ChangeRequest, $this> */
    public function changes(): HasMany
    {
        return $this->hasMany(ChangeRequest::class);
    }

    /** @return HasMany<FamilyEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(FamilyEvent::class);
    }

    /** @return HasMany<GedcomImport, $this> */
    public function imports(): HasMany
    {
        return $this->hasMany(GedcomImport::class);
    }

    /** @return HasMany<ReportExport, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(ReportExport::class);
    }

    /** @return HasMany<FamilyPhoto, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(FamilyPhoto::class);
    }

    /** @return HasMany<PhotoAlbum, $this> */
    public function albums(): HasMany
    {
        return $this->hasMany(PhotoAlbum::class);
    }

    /** @return HasMany<FamilyDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(FamilyDocument::class);
    }

    /** @return BelongsTo<FamilyMember, $this> */
    public function rootMember(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class, 'root_member_id');
    }

    /** Families are addressed by slug in URLs. */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Mark the shape of the tree as changed. `graph_version` is the cache key for tree and
     * relationship data, so anything cached for the previous version is never used again.
     */
    public static function bumpGraphVersion(string $familyId): void
    {
        static::query()->withTrashed()->whereKey($familyId)->toBase()->increment('graph_version');
    }

    /** The current version straight from the database (the loaded model may be stale). */
    public function currentGraphVersion(): int
    {
        return (int) static::query()->withTrashed()->whereKey($this->id)->toBase()->value('graph_version');
    }

    /** @return BelongsToMany<User, $this, FamilyMembership> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'family_user')
            ->using(FamilyMembership::class)
            ->withPivot(['id', 'role_id', 'status', 'linked_member_id', 'joined_at'])
            ->withTimestamps();
    }
}
