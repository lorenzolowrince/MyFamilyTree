<?php

namespace App\Services\Genealogy;

use App\Enums\ParentSubtype;
use App\Exceptions\InvalidRelationshipException;
use App\Models\FamilyMember;

/**
 * The checks the database trigger also makes, run first so people get a clear explanation
 * instead of a database error. Pure: it only looks at the graph it is given.
 */
final class RelationshipRules
{
    /**
     * @throws InvalidRelationshipException
     */
    public function assertCanBeParent(FamilyGraph $graph, FamilyMember $parent, FamilyMember $child, ParentSubtype $subtype): void
    {
        $this->assertDifferentPeople($parent, $child);

        if ($graph->isParentOf($parent->id, $child->id)) {
            throw new InvalidRelationshipException(__(':parent is already recorded as a parent of :child.', $this->names($parent, $child)));
        }

        if ($graph->isParentOf($child->id, $parent->id)) {
            throw new InvalidRelationshipException(__(':parent cannot be a parent of :child, because :child is already a parent of :parent.', $this->names($parent, $child)));
        }

        if ($graph->isAncestorOf($child->id, $parent->id)) {
            throw new InvalidRelationshipException(__(':parent cannot be a parent of :child, because :child is already an ancestor of :parent.', $this->names($parent, $child)));
        }

        if ($graph->arePartners($parent->id, $child->id)) {
            throw new InvalidRelationshipException(__(':parent and :child are recorded as partners, so one cannot be the parent of the other.', $this->names($parent, $child)));
        }

        if ($subtype === ParentSubtype::Biological && count($graph->biologicalParentIds($child->id)) >= 2) {
            throw new InvalidRelationshipException(__(':child already has two biological parents. Add :parent as an adoptive, step, foster or guardian parent instead, or correct the existing parents first.', $this->names($parent, $child)));
        }
    }

    /**
     * @throws InvalidRelationshipException
     */
    public function assertCanBePartners(FamilyGraph $graph, FamilyMember $one, FamilyMember $other): void
    {
        $this->assertDifferentPeople($one, $other);

        if ($graph->isParentOf($one->id, $other->id) || $graph->isParentOf($other->id, $one->id)) {
            throw new InvalidRelationshipException(__(':parent and :child are recorded as parent and child, so they cannot also be partners.', $this->names($one, $other)));
        }
    }

    /**
     * @throws InvalidRelationshipException
     */
    private function assertDifferentPeople(FamilyMember $one, FamilyMember $other): void
    {
        if ($one->id === $other->id) {
            throw new InvalidRelationshipException(__('A person cannot be related to themselves.'));
        }

        if ($one->family_id !== $other->family_id) {
            throw new InvalidRelationshipException(__('Both people must belong to the same family tree.'));
        }
    }

    /**
     * @return array{parent: string, child: string}
     */
    private function names(FamilyMember $parent, FamilyMember $child): array
    {
        return ['parent' => $parent->full_name, 'child' => $child->full_name];
    }
}
