<?php

namespace App\Enums;

/**
 * Who in the family may see a record. It narrows access, never widens it
 * (docs/architecture/05-roles-permissions.md).
 */
enum Visibility: string
{
    /** Every member of the family, subject to their role. */
    case Family = 'family';

    /** Editors and above. */
    case Restricted = 'restricted';

    /** Owner and Administrators only. */
    case Private = 'private';

    /** The permission a role needs to see a record with this visibility. */
    public function requiredPermission(): FamilyPermission
    {
        return match ($this) {
            self::Family => FamilyPermission::TreeView,
            self::Restricted => FamilyPermission::MembersViewRestricted,
            self::Private => FamilyPermission::MembersViewPrivate,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Family => 'Whole family',
            self::Restricted => 'Editors and above',
            self::Private => 'Owner and administrators only',
        };
    }
}
