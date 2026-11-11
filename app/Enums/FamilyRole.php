<?php

namespace App\Enums;

/**
 * Family-level roles (docs/architecture/05-roles-permissions.md).
 * The ids and ranks here are the source for the seeded `family_roles` table.
 */
enum FamilyRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Editor = 'editor';
    case Contributor = 'contributor';
    case Viewer = 'viewer';

    /** Primary key in `family_roles`. Never renumber: memberships reference it. */
    public function id(): int
    {
        return match ($this) {
            self::Owner => 1,
            self::Admin => 2,
            self::Editor => 3,
            self::Contributor => 4,
            self::Viewer => 5,
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Owner => 50,
            self::Admin => 40,
            self::Editor => 30,
            self::Contributor => 20,
            self::Viewer => 10,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Administrator',
            self::Editor => 'Editor',
            self::Contributor => 'Contributor',
            self::Viewer => 'Viewer',
        };
    }

    public static function fromId(int $id): self
    {
        foreach (self::cases() as $role) {
            if ($role->id() === $id) {
                return $role;
            }
        }

        throw new \ValueError("Unknown family role id [{$id}].");
    }

    public function outranks(self $other): bool
    {
        return $this->rank() > $other->rank();
    }

    /** Owners and Administrators must protect their account with two-factor authentication. */
    public function requiresTwoFactor(): bool
    {
        return $this->rank() >= self::Admin->rank();
    }
}
