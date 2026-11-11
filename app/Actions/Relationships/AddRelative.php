<?php

namespace App\Actions\Relationships;

use App\Actions\Members\CreateMember;
use App\Enums\ParentSubtype;
use App\Enums\RelativeKind;
use App\Exceptions\InvalidRelationshipException;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\User;
use App\Services\Genealogy\GraphChange;

/**
 * The "Add parent / child / partner / sibling" flow. The relative is either someone already
 * in the tree (linked, never copied) or a new person created in the same step. Everything
 * happens in one transaction: if the link is refused, no stray person is left behind.
 */
class AddRelative
{
    public function __construct(
        private readonly GraphChange $graphChange,
        private readonly CreateMember $createMember,
        private readonly LinkParentAndChild $linkParent,
        private readonly LinkPartners $linkPartners,
        private readonly ConnectSiblings $connectSiblings,
    ) {}

    /**
     * @param  FamilyMember|array<string, mixed>  $relative  an existing person, or the fields of a new one
     * @param  array{subtype?: string|null, status?: string|null, start?: array<string, mixed>|null, end?: array<string, mixed>|null, place?: string|null, other_parent_id?: string|null, shared_parent_ids?: list<string>|null}  $options
     *
     * @throws InvalidRelationshipException
     */
    public function handle(Family $family, User $actor, FamilyMember $anchor, RelativeKind $kind, FamilyMember|array $relative, array $options = []): FamilyMember
    {
        return $this->graphChange->run($family, function () use ($family, $actor, $anchor, $kind, $relative, $options): FamilyMember {
            $person = $relative instanceof FamilyMember
                ? $relative
                : $this->createMember->handle($family, $actor, $relative);

            $subtype = ParentSubtype::tryFrom((string) ($options['subtype'] ?? '')) ?? ParentSubtype::Biological;

            switch ($kind) {
                case RelativeKind::Parent:
                    $this->linkParent->handle($family, $actor, $person, $anchor, $subtype);
                    break;

                case RelativeKind::Child:
                    $this->linkParent->handle($family, $actor, $anchor, $person, $subtype);

                    if (($options['other_parent_id'] ?? null) !== null) {
                        $other = FamilyMember::query()->inFamily($family)->findOrFail($options['other_parent_id']);
                        $this->linkParent->handle($family, $actor, $other, $person, $subtype);
                    }
                    break;

                case RelativeKind::Partner:
                    $this->linkPartners->handle($family, $actor, $anchor, $person, $options);
                    break;

                case RelativeKind::Sibling:
                    $this->connectSiblings->handle($family, $actor, $anchor, $person, $options['shared_parent_ids'] ?? null);
                    break;
            }

            return $person;
        });
    }
}
