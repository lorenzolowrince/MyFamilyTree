<?php

namespace App\Models;

use App\Enums\FamilyRole;
use App\Enums\MembershipStatus;
use App\Enums\PushTopic;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use NotificationChannels\WebPush\HasPushSubscriptions;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $password Null for accounts that only sign in with Google.
 * @property bool $is_super_admin
 * @property UserStatus $status
 * @property string $locale
 * @property string $timezone
 * @property string|null $avatar_path
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property Carbon|null $last_login_at
 * @property Carbon|null $deletion_scheduled_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property list<string>|null $push_topics Null means every topic.
 */
#[Fillable(['name', 'email', 'password', 'locale', 'timezone'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, HasUuids, Notifiable, PasskeyAuthenticatable, SoftDeletes, TwoFactorAuthenticatable;

    /** Days between asking for account deletion and the account being erased. */
    public const DELETION_GRACE_DAYS = 14;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_super_admin' => false,
        'status' => UserStatus::Active->value,
        'locale' => 'en',
        'timezone' => 'Asia/Kuala_Lumpur',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'status' => UserStatus::class,
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'deletion_scheduled_at' => 'datetime',
            'push_topics' => 'array',
        ];
    }

    /**
     * The kinds of push notification this person wants. Push is only sent to devices they
     * turned it on for (Settings → Notifications).
     *
     * @return list<PushTopic>
     */
    public function pushTopics(): array
    {
        if ($this->push_topics === null) {
            return PushTopic::cases();
        }

        return array_values(array_filter(array_map(
            fn (string $value): ?PushTopic => PushTopic::tryFrom($value),
            $this->push_topics,
        )));
    }

    public function wantsPush(PushTopic $topic): bool
    {
        return $this->status === UserStatus::Active
            && in_array($topic, $this->pushTopics(), true)
            && $this->pushSubscriptions()->exists();
    }

    /** @return HasMany<SocialAccount, $this> */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /** @return HasMany<LoginActivity, $this> */
    public function loginActivities(): HasMany
    {
        return $this->hasMany(LoginActivity::class);
    }

    /** @return HasMany<FamilyMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(FamilyMembership::class);
    }

    /** @return BelongsToMany<Family, $this, FamilyMembership> */
    public function families(): BelongsToMany
    {
        return $this->belongsToMany(Family::class, 'family_user')
            ->using(FamilyMembership::class)
            ->withPivot(['id', 'role_id', 'status', 'linked_member_id', 'joined_at'])
            ->withTimestamps();
    }

    /** @return HasMany<Family, $this> */
    public function ownedFamilies(): HasMany
    {
        return $this->hasMany(Family::class, 'owner_id');
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function hasPassword(): bool
    {
        return $this->password !== null;
    }

    /**
     * Super Administrators and the Owners/Administrators of a family must use two-factor
     * authentication (docs/architecture/06-security.md).
     */
    public function mustUseTwoFactor(): bool
    {
        if ($this->is_super_admin) {
            return true;
        }

        if (! config('families.require_two_factor_for_admins')) {
            return false;
        }

        return $this->memberships()
            ->where('status', MembershipStatus::Active->value)
            ->whereIn('role_id', [FamilyRole::Owner->id(), FamilyRole::Admin->id()])
            ->exists();
    }
}
