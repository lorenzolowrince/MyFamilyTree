<?php

namespace App\Models;

use App\Enums\NameType;
use App\Services\Audit\HasHistory;
use App\Services\Audit\RecordsHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Another name a person is or was known by. Included in name search.
 *
 * @property string $id
 * @property string $family_id
 * @property string $member_id
 * @property NameType $type
 * @property string $name
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MemberName extends Model implements HasHistory
{
    use HasUuids, RecordsHistory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['type' => NameType::class];
    }

    /** @return BelongsTo<FamilyMember, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class, 'member_id');
    }

    public function historyNoun(): string
    {
        return 'member_name';
    }

    /** Names are audited but too minor for the activity feed. */
    public function historySummary(string $event): ?string
    {
        return null;
    }
}
