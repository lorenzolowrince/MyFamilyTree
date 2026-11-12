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
    /**
     * Input fields that hold a living person's personal details. MemberPresenter hides them
     * from viewers without `canViewSensitive`; those viewers may not change them either.
     */
    public const SENSITIVE_FIELDS = ['birth', 'birth_place', 'hometown', 'occupation', 'education', 'nationality', 'biography'];

    /** Input fields for editors and above. */
    public const EDITOR_FIELDS = ['notes', 'visibility'];

    public function __construct(private readonly FamilyAccess $access) {}

    /**
     * Removes from an edit the fields this viewer cannot see on this person.
     *
     * Someone who cannot see a field is shown it as empty, so their edit would otherwise
     * erase it, and the "current value" of a proposal would reveal it.
     *
     * @param  array<string, mixed>  $data  validated member fields
     * @return array<string, mixed>
     */
    public function editableFields(array $data, FamilyMember $member, MemberViewer $viewer): array
    {
        $hidden = [
            ...($viewer->canViewSensitive($member) ? [] : self::SENSITIVE_FIELDS),
            ...($viewer->canViewNotes($member) ? [] : self::EDITOR_FIELDS),
        ];

        return array_diff_key($data, array_flip($hidden));
    }

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
