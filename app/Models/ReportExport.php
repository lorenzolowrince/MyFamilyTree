<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A PDF report someone asked for (`report_exports`). The file is made by a background job and
 * deleted when it expires.
 *
 * @property string $id
 * @property string $family_id
 * @property string|null $requested_by
 * @property string $type
 * @property string $title
 * @property array<string, mixed> $parameters
 * @property string $status
 * @property string|null $storage_key
 * @property int|null $size_bytes
 * @property string|null $error
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $requester
 */
class ReportExport extends Model
{
    use HasUuids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'parameters' => '{}',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'size_bytes' => 'integer',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Family, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isReady(): bool
    {
        return $this->status === 'ready' && $this->storage_key !== null && $this->expires_at->isFuture();
    }
}
