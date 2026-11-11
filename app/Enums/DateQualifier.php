<?php

namespace App\Enums;

/** Maps one-to-one onto GEDCOM 7 date qualifiers (ABT, EST, CAL, BEF, AFT, BET). */
enum DateQualifier: string
{
    case Exact = 'exact';
    case About = 'about';
    case Estimated = 'estimated';
    case Calculated = 'calculated';
    case Before = 'before';
    case After = 'after';
    case Between = 'between';
}
