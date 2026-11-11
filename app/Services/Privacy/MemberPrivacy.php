<?php

namespace App\Services\Privacy;

use App\Enums\FamilyPermission;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\User;
use App\Services\Authorization\FamilyAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the viewer for a user in a family, and scopes queries to what that viewer may see.
 */
class MemberPrivacy
{
    public function __construct(private readonly FamilyAccess $access) {}

    public function viewer(User $user, Family $family): MemberViewer
    {
        $membership = $this->access->membership($user, $family);
        $role = $membership?->role;

        $permissions = [];

        if ($role !== null) {
            foreach (FamilyPermission::cases() as $permission) {
                if ($this->access->roleHas($role, $permission)) {
                    $permissions[] = $permission->value;
                }
            }
        }

        return new MemberViewer($family->id, $role, $permissions, $membership?->linked_member_id);
    }

    /**
     * Limit a member query to records the viewer may open (their own profile included).
     *
     * @param  Builder<FamilyMember>  $query
     * @return Builder<FamilyMember>
     */
    public function scopeVisible(Builder $query, MemberViewer $viewer): Builder
    {
        return $query->where(function (Builder $query) use ($viewer): void {
            $query->whereIn('visibility', $viewer->visibleLevels());

            if ($viewer->linkedMemberId !== null) {
                $query->orWhere('family_members.id', $viewer->linkedMemberId);
            }
        });
    }
}
