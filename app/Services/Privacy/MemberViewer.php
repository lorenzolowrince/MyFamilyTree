<?php

namespace App\Services\Privacy;

use App\Enums\FamilyPermission;
use App\Enums\FamilyRole;
use App\Enums\Visibility;
use App\Models\FamilyMember;

/**
 * What one signed-in person may see of the people in one family
 * (docs/architecture/05-roles-permissions.md, "Rules that apply on top of the matrix").
 *
 * Built once per request by MemberPrivacy; every list, tree, search and profile asks it, so a
 * hidden person cannot be found through a side door.
 */
final readonly class MemberViewer
{
    /**
     * @param  list<string>  $permissions  permission keys held by the viewer's role
     */
    public function __construct(
        public string $familyId,
        public ?FamilyRole $role,
        public array $permissions,
        public ?string $linkedMemberId,
    ) {}

    public function has(FamilyPermission $permission): bool
    {
        return in_array($permission->value, $this->permissions, true);
    }

    public function isSelf(FamilyMember $member): bool
    {
        return $this->linkedMemberId !== null && $this->linkedMemberId === $member->id;
    }

    /** May the viewer see that this record exists and open it? */
    public function canView(FamilyMember $member): bool
    {
        if ($this->role === null || $member->family_id !== $this->familyId) {
            return false;
        }

        return $this->isSelf($member) || $this->has($member->visibility->requiredPermission());
    }

    /** Exact dates, places and other personal details of a living person. */
    public function canViewSensitive(FamilyMember $member): bool
    {
        if (! $this->canView($member)) {
            return false;
        }

        return ! $member->isPresumedLiving()
            || $this->isSelf($member)
            || $this->has(FamilyPermission::MembersViewLivingSensitive);
    }

    /** Research notes are for editors and above. */
    public function canViewNotes(FamilyMember $member): bool
    {
        return $this->canView($member) && $this->has(FamilyPermission::MembersUpdate);
    }

    /**
     * The visibility levels this viewer may list, for query scoping.
     *
     * @return list<string>
     */
    public function visibleLevels(): array
    {
        $levels = [];

        foreach (Visibility::cases() as $visibility) {
            if ($this->role !== null && $this->has($visibility->requiredPermission())) {
                $levels[] = $visibility->value;
            }
        }

        return $levels;
    }
}
