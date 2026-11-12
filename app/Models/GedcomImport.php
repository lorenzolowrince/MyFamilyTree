<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One uploaded GEDCOM file on its way into a family tree (`gedcom_imports`).
 *
 * uploaded → parsing → preview_ready → importing → completed, or failed / cancelled.
 * Nothing touches the tree before a person has looked at the preview and confirmed.
 *
 * @property string $id
 * @property string $family_id
 * @property string|null $uploaded_by
 * @property string $storage_key
 * @property string|null $original_filename
 * @property string $status
 * @property array<string, mixed> $summary
 * @property array<string, string> $mapping
 * @property array<string, mixed>|null $result
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $uploader
 */
class GedcomImport extends Model
{
    use HasUuids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'uploaded',
        'summary' => '{}',
        'mapping' => '{}',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'mapping' => 'array',
            'result' => 'array',
        ];
    }

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

    /** Still being worked on by a background job. */
    public function isBusy(): bool
    {
        return in_array($this->status, ['uploaded', 'parsing', 'importing'], true);
    }
}
