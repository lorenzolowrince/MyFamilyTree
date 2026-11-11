<?php

namespace App\Enums;

/** What the "Add relative" form is adding, seen from the person it was opened on. */
enum RelativeKind: string
{
    case Parent = 'parent';
    case Child = 'child';
    case Partner = 'partner';
    case Sibling = 'sibling';
}
