<?php

use App\Actions\Relationships\LinkParentAndChild;
use App\Actions\Relationships\LinkPartners;
use App\Enums\FamilyRole;
use App\Enums\ParentSubtype;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\FamilyRelationship;
use App\Models\User;
use Database\Factories\FamilyFactory;

/*
| Helpers for tests that build family trees. Loaded from tests/Pest.php.
*/

/**
 * A family whose owner has two-factor on (required for Owners and Administrators).
 *
 * @return array{0: Family, 1: User}
 */
function treeFamily(): array
{
    $owner = User::factory()->withTwoFactor()->create();
    $family = Family::factory()->create(['owner_id' => $owner->id]);

    return [$family, $owner];
}

/** A user holding the given role in the family (with two-factor on where the role needs it). */
function memberWithRole(Family $family, FamilyRole $role): User
{
    if ($role === FamilyRole::Owner) {
        return $family->owner;
    }

    $user = User::factory()->withTwoFactor()->create();
    FamilyFactory::addMember($family, $user, $role);

    return $user;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function person(Family $family, string $name, array $attributes = []): FamilyMember
{
    return FamilyMember::factory()->create(['family_id' => $family->id, 'full_name' => $name, ...$attributes]);
}

function parentOf(FamilyMember $parent, FamilyMember $child, ParentSubtype $subtype = ParentSubtype::Biological): FamilyRelationship
{
    return app(LinkParentAndChild::class)->handle($parent->family, null, $parent, $child, $subtype);
}

/**
 * @param  array<string, mixed>  $details
 */
function partners(FamilyMember $one, FamilyMember $other, array $details = []): FamilyRelationship
{
    return app(LinkPartners::class)->handle($one->family, null, $one, $other, $details);
}
