<?php

namespace App\Models;

use App\Enums\FamilyRole;
use App\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * A user's place in one family: exactly one role per user per family (`family_user`).
 *
 * @property string $id
 * @property string $family_id
 * @property string $user_id
 * @property int $role_id
 * @property FamilyRole $role
 * @property string|null $linked_member_id
 * @property MembershipStatus $status
 * @property string|null $invited_by
 * @property Carbon|null $joined_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FamilyMembership extends Pivot
{
    use HasUuids;

    protected $table = 'family_user';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => MembershipStatus::Active->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role_id' => 'integer',
            'status' => MembershipStatus::class,
            'joined_at' => 'datetime',
        ];
    }

    /** @return Attribute<FamilyRole, FamilyRole> */
    protected function role(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes): FamilyRole => FamilyRole::fromId((int) $attributes['role_id']),
            set: fn (FamilyRole $role): array => ['role_id' => $role->id()],
        );
    }

    /** @return BelongsTo<Family, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }
}
