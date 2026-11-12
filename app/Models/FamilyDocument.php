<?php

namespace App\Models;

use App\Concerns\IsMediaFile;
use App\Enums\DocumentCategory;
use App\Enums\MediaStatus;
use App\Enums\Visibility;
use App\Support\FuzzyDate\FuzzyDate;
use App\Support\FuzzyDate\FuzzyDateCast;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A document kept by a family: a certificate, letter, land record... (`family_documents`).
 *
 * @property string $id
 * @property string $family_id
 * @property DocumentCategory $category
 * @property int|null $page_count
 * @property string|null $preview_key
 * @property bool $downloadable
 * @property string $storage_key
 * @property array<string, mixed> $variants
 * @property string|null $original_filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property string $sha256
 * @property FuzzyDate $issued
 * @property string|null $title
 * @property string|null $description
 * @property MediaStatus $status
 * @property string|null $rejection_reason
 * @property Visibility $visibility
 * @property string|null $uploaded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $uploader
 * @property-read Collection<int, FamilyMember> $people
 */
class FamilyDocument extends Model
{
    use HasUuids, IsMediaFile, SoftDeletes;

    public const MEDIA_FOLDER = 'documents';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'variants' => '{}',
        'status' => 'quarantined',
        'visibility' => 'family',
        'category' => 'other',
        'downloadable' => true,
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
            'page_count' => 'integer',
            'downloadable' => 'boolean',
            'category' => DocumentCategory::class,
            'status' => MediaStatus::class,
            'visibility' => Visibility::class,
            'issued' => FuzzyDateCast::class,
        ];
    }

    /**
     * People the document is about.
     *
     * @return BelongsToMany<FamilyMember, $this>
     */
    public function people(): BelongsToMany
    {
        return $this->belongsToMany(FamilyMember::class, 'member_documents', 'document_id', 'member_id')
            ->withPivot(['family_id']);
    }

    /**
     * Object keys of the page images shown in the viewer.
     *
     * @return list<string>
     */
    public function pageKeys(): array
    {
        $pages = $this->variants['pages'] ?? [];

        return is_array($pages) ? array_values(array_filter($pages, 'is_string')) : [];
    }

    /** The name offered when the file is downloaded: built by the server, never the upload's own. */
    public function downloadName(): string
    {
        $base = preg_replace('/[^\pL\pN _-]+/u', '', (string) ($this->title ?? '')) ?? '';
        $base = trim($base) === '' ? 'document' : trim($base);

        return mb_substr($base, 0, 80).'.'.($this->mime_type === 'application/pdf' ? 'pdf' : 'jpg');
    }
}
