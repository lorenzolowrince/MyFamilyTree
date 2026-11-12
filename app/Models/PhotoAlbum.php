<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A named group of photos (`photo_albums`). A photo is in at most one album.
 *
 * @property string $id
 * @property string $family_id
 * @property string $title
 * @property string|null $description
 * @property string|null $cover_photo_id
 * @property string|null $family_event_id
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class PhotoAlbum extends Model
{
    use HasUuids, SoftDeletes;

    /** @return BelongsTo<Family, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /** @return HasMany<FamilyPhoto, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(FamilyPhoto::class, 'album_id');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeInFamily(Builder $query, Family $family): void
    {
        $query->where($this->qualifyColumn('family_id'), $family->id);
    }
}
