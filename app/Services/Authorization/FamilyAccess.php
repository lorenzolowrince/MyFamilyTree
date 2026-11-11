<?php

namespace App\Services\Authorization;

use App\Enums\FamilyPermission;
use App\Enums\FamilyRole;
use App\Enums\MembershipStatus;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Answers "what may this user do in this family?" from the seeded matrix.
 *
 * Every Policy goes through this class, so membership status and the role matrix are
 * checked in exactly one place. Bound as a singleton: lookups are memoised per request.
 */
class FamilyAccess
{
    private const CACHE_KEY = 'family-access:matrix';

    /** @var array<string, list<string>>|null role key => permission keys */
    private ?array $matrix = null;

    /** @var array<string, FamilyMembership|null> */
    private array $memberships = [];

    /**
     * The user's active membership of the family, or null when they are not an active member
     * (never a member, suspended in this family, or the account itself is not active).
     */
    public function membership(User $user, Family $family): ?FamilyMembership
    {
        if (! $user->isActive()) {
            return null;
        }

        $key = $user->id.'|'.$family->id;

        if (! array_key_exists($key, $this->memberships)) {
            $this->memberships[$key] = FamilyMembership::query()
                ->where('family_id', $family->id)
                ->where('user_id', $user->id)
                ->where('status', MembershipStatus::Active->value)
                ->first();
        }

        return $this->memberships[$key];
    }

    public function isMember(User $user, Family $family): bool
    {
        return $this->membership($user, $family) !== null;
    }

    public function role(User $user, Family $family): ?FamilyRole
    {
        return $this->membership($user, $family)?->role;
    }

    public function can(User $user, Family $family, FamilyPermission $permission): bool
    {
        $role = $this->role($user, $family);

        return $role !== null && $this->roleHas($role, $permission);
    }

    public function roleHas(FamilyRole $role, FamilyPermission $permission): bool
    {
        return in_array($permission->value, $this->matrix()[$role->value] ?? [], true);
    }

    /**
     * "Nobody can grant a role equal to or higher than their own": may the user give
     * (or take away) the given role in this family?
     */
    public function canAssignRole(User $user, Family $family, FamilyRole $role): bool
    {
        $own = $this->role($user, $family);

        return $own !== null
            && $this->roleHas($own, FamilyPermission::RolesAssign)
            && $own->outranks($role);
    }

    /** Forget cached lookups (after seeding, or when a membership changes mid-request). */
    public function forgetMatrix(): void
    {
        $this->matrix = null;
        $this->memberships = [];

        Cache::forget(self::CACHE_KEY);
    }

    public function forgetMemberships(): void
    {
        $this->memberships = [];
    }

    /**
     * @return array<string, list<string>>
     */
    private function matrix(): array
    {
        return $this->matrix ??= Cache::rememberForever(self::CACHE_KEY, function (): array {
            $matrix = [];

            $rows = DB::table('family_role_permissions')
                ->join('family_roles', 'family_roles.id', '=', 'family_role_permissions.role_id')
                ->join('family_permissions', 'family_permissions.id', '=', 'family_role_permissions.permission_id')
                ->get(['family_roles.key as role', 'family_permissions.key as permission']);

            foreach ($rows as $row) {
                $matrix[(string) $row->role][] = (string) $row->permission;
            }

            return $matrix;
        });
    }
}
