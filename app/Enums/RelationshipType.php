<?php

namespace App\Enums;

/**
 * The only two kinds of edge that are stored. Siblings, grandparents, cousins and in-laws
 * are derived from these (docs/architecture/03-relationship-modelling.md).
 */
enum RelationshipType: string
{
    /** person_a is a parent of person_b. */
    case ParentChild = 'parent_child';

    /** person_a and person_b are or were partners (stored with a < b). */
    case Partnership = 'partnership';
}
