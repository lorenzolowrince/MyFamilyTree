<?php

namespace App\Enums;

/** How much of a historical date is known (docs/architecture/02-database.md, fuzzy dates). */
enum DatePrecision: string
{
    case Day = 'day';
    case Month = 'month';
    case Year = 'year';
    case Decade = 'decade';
    case Unknown = 'unknown';
}
