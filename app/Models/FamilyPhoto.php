<?php

namespace App\Models;

use App\Concerns\IsMediaFile;
use App\Enums\MediaStatus;
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
 * A photo in a family's gallery (`family_photos`).
 *
 * @property string $id
 * @property string $family_id
 * @property string|null $album_id
 * @property string $storage_key
 * @property array<string, mixed> $variants
 * @property string|null $original_filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property string $sha256
 * @property FuzzyDate $taken
 * @property string|null $caption
 * @property string|null $description
 * @property MediaStatus $status
 * @property string|null $rejection_reason
 * @property Visibility $visibility
 * @property string|null $uploaded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read PhotoAlbum|null $album
 * @property-read User|null $uploader
 * @property-read Collection<int, FamilyMember> $people
 */
class FamilyPhoto extends Model
{
    use HasUuids, IsMediaFile, SoftDeletes;

    public const MEDIA_FOLDER = 'photos';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'variants' => '{}',
        'status' => 'quarantined',
        'visibility' => 'family',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'variants' => 'array',
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'status' => MediaStatus::class,
            'visibility' => Visibility::class,
            'taken' => FuzzyDateCast::class,
        ];
    }

    /** @return BelongsTo<PhotoAlbum, $this> */
    public function album(): BelongsTo
    {
        return $this->belongsTo(PhotoAlbum::class, 'album_id');
    }

    /**
     * People tagged in the photo.
     *
     * @return BelongsToMany<FamilyMember, $this>
     */
    public function people(): BelongsToMany
    {
        return $this->belongsToMany(FamilyMember::class, 'member_photos', 'photo_id', 'member_id')
            ->withPivot(['family_id', 'is_primary']);
    }

    /** The object key of one stored size (`thumb`, `display`), or the full-size image. */
    public function keyFor(string $size): string
    {
        $key = $this->variants[$size] ?? null;

        return is_string($key) ? $key : $this->storage_key;
    }
}
