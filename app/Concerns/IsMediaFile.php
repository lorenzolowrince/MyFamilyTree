<?php

namespace App\Concerns;

use App\Enums\MediaStatus;
use App\Models\Family;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What photos and documents share: where the file is stored, its place in the safety
 * pipeline, and who may list it.
 *
 * @mixin Model
 */
trait IsMediaFile
{
    /** @return BelongsTo<Family, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isReady(): bool
    {
        return $this->status === MediaStatus::Ready;
    }

    /** The folder in object storage that holds every stored form of this file. */
    public function storageFolder(): string
    {
        return "families/{$this->family_id}/".self::MEDIA_FOLDER."/{$this->id}";
    }

    /**
     * Every object key this record owns.
     *
     * @return list<string>
     */
    public function storageKeys(): array
    {
        $keys = [$this->storage_key];
        $variants = $this->variants;

        array_walk_recursive($variants, function (mixed $value) use (&$keys): void {
            if (is_string($value)) {
                $keys[] = $value;
            }
        });

        if (is_string($this->getAttribute('preview_key'))) {
            $keys[] = $this->getAttribute('preview_key');
        }

        return array_values(array_unique(array_filter($keys)));
    }

    /**
     * Files this person may see listed: ready ones at a visibility level their role allows,
     * plus their own uploads that are still being checked or were refused.
     *
     * @param  Builder<static>  $query
     * @param  list<string>  $levels
     */
    public function scopeVisibleTo(Builder $query, User $user, array $levels): void
    {
        $query->where(function (Builder $query) use ($user, $levels): void {
            $query->where(fn (Builder $ready) => $ready->where('status', MediaStatus::Ready->value)->whereIn('visibility', $levels))
                ->orWhere(fn (Builder $mine) => $mine->where('uploaded_by', $user->id)->where('status', '<>', MediaStatus::Ready->value));
        });
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeInFamily(Builder $query, Family $family): void
    {
        $query->where($this->qualifyColumn('family_id'), $family->id);
    }
}
