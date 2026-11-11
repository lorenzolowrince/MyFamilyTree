<?php

namespace App\Actions\Relationships;

use App\Actions\Members\CreateMember;
use App\Enums\ParentSubtype;
use App\Exceptions\InvalidRelationshipException;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\User;
use App\Services\Genealogy\FamilyGraphLoader;
use App\Services\Genealogy\GraphChange;

/**
 * Makes two people siblings. Siblings are never stored as such: they are people who share a
 * parent. So the new sibling is linked to the person's parents, and when no parent is known
 * an "Unknown parent" placeholder holds the two together until the real parent is identified.
 */
class ConnectSiblings
{
    public function __construct(
        private readonly GraphChange $graphChange,
        private readonly FamilyGraphLoader $graphs,
        private readonly LinkParentAndChild $linkParent,
        private readonly CreateMember $createMember,
    ) {}

    /**
     * @param  list<string>|null  $sharedParentIds  which of the person's parents the sibling shares
     *                                              (null = all of them; one of two = half-sibling)
     *
     * @throws InvalidRelationshipException
     */
    public function handle(Family $family, ?User $actor, FamilyMember $person, FamilyMember $sibling, ?array $sharedParentIds = null): void
    {
        $this->graphChange->run($family, function () use ($family, $actor, $person, $sibling, $sharedParentIds): void {
            if ($person->id === $sibling->id) {
                throw new InvalidRelationshipException(__('A person cannot be their own sibling.'));
            }

            $graph = $this->graphs->for($family);
            $parentLinks = $graph->parentLinks($person->id);

            if ($sharedParentIds !== null) {
                $parentLinks = array_values(array_filter($parentLinks, fn (array $link): bool => in_array($link['person'], $sharedParentIds, true)));

                if ($parentLinks === []) {
                    throw new InvalidRelationshipException(__('Choose at least one parent the two share.'));
                }
            }

            if ($parentLinks === []) {
                $placeholder = $this->createMember->handle($family, $actor, ['full_name' => __('Unknown parent')], placeholder: true);

                $this->linkParent->handle($family, $actor, $placeholder, $person);
                $this->linkParent->handle($family, $actor, $placeholder, $sibling);

                return;
            }

            $linked = 0;

            foreach ($parentLinks as $link) {
                if ($graph->isParentOf($link['person'], $sibling->id)) {
                    continue;
                }

                $parent = FamilyMember::query()->inFamily($family)->findOrFail($link['person']);

                $this->linkParent->handle($family, $actor, $parent, $sibling, ParentSubtype::from($link['subtype']));
                $linked++;
            }

            if ($linked === 0) {
                throw new InvalidRelationshipException(__(':one and :other are already siblings.', ['one' => $person->full_name, 'other' => $sibling->full_name]));
            }
        });
    }
}
