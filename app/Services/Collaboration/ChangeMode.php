<?php

namespace App\Services\Collaboration;

use App\Enums\FamilyPermission;
use App\Enums\FamilyRole;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\User;
use App\Services\Authorization\FamilyAccess;

/**
 * Decides how a change by this person is handled: applied at once, submitted for approval,
 * or refused (docs/architecture/05-roles-permissions.md).
 *
 * The family's approval policy can tighten or loosen the defaults:
 * - `approval.editors_need_approval`: Editors' changes also wait for approval;
 * - `approval.contributors_publish_directly`: Contributors' additions and edits apply at once.
 *
 * Owners and Administrators always apply directly. Deleting is never loosened.
 */
class ChangeMode
{
    public const DIRECT = 'direct';

    public const PROPOSE = 'propose';

    public const DENY = 'deny';

    public function __construct(private readonly FamilyAccess $access) {}

    /** Adding people, links between people, and timeline entries; editing people. */
    public function forEdit(User $user, Family $family, ?FamilyMember $ownProfile = null): string
    {
        $membership = $this->access->membership($user, $family);
        $role = $membership?->role;

        if ($role === null) {
            return self::DENY;
        }

        $isOwnProfile = $ownProfile !== null && $membership->linked_member_id === $ownProfile->id;

        return match ($role) {
            FamilyRole::Owner, FamilyRole::Admin => self::DIRECT,
            FamilyRole::Editor => $this->setting($family, 'editors_need_approval') ? self::PROPOSE : self::DIRECT,
            FamilyRole::Contributor => $isOwnProfile || $this->setting($family, 'contributors_publish_directly') ? self::DIRECT : self::PROPOSE,
            // A Viewer may propose changes to their own linked profile only.
            FamilyRole::Viewer => $isOwnProfile ? self::PROPOSE : self::DENY,
        };
    }

    /** Removing a person: Owners and Administrators directly, Editors by proposal. */
    public function forDelete(User $user, Family $family): string
    {
        return match (true) {
            $this->access->can($user, $family, FamilyPermission::MembersDelete) => self::DIRECT,
            $this->access->role($user, $family) === FamilyRole::Editor => self::PROPOSE,
            default => self::DENY,
        };
    }

    private function setting(Family $family, string $key): bool
    {
        return (bool) ($family->settings['approval'][$key] ?? false);
    }
}
