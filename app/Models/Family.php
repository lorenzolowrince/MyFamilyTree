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

    /** @return BelongsToMany<User, $this, FamilyMembership> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'family_user')
            ->using(FamilyMembership::class)
            ->withPivot(['id', 'role_id', 'status', 'linked_member_id', 'joined_at'])
            ->withTimestamps();
    }
}
