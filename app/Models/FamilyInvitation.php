<?php

namespace App\Models;

use App\Enums\FamilyRole;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An invitation to join a family. Only a SHA-256 of the token is stored; the token itself
 * exists only in the link that was emailed.
 *
 * @property string $id
 * @property string $family_id
 * @property string $email
 * @property int $role_id
 * @property FamilyRole $role
 * @property string $token_hash
 * @property string|null $member_id
 * @property string|null $invited_by
 * @property string|null $message
 * @property CarbonInterface $expires_at
 * @property Carbon|null $accepted_at
 * @property string|null $accepted_by
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Family|null $family
 * @property-read User|null $inviter
 */
class FamilyInvitation extends Model
{
    use HasUuids;

    public const VALID_DAYS = 7;

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role_id' => 'integer',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
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
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isOpen(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function state(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'accepted',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default => 'pending',
        };
    }
}
