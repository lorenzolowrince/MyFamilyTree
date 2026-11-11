<?php

namespace App\Enums;

enum ParentSubtype: string
{
    case Biological = 'biological';
    case Adoptive = 'adoptive';
    case Step = 'step';
    case Foster = 'foster';
    case Guardian = 'guardian';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Biological => 'Biological',
            self::Adoptive => 'Adoptive',
            self::Step => 'Step',
            self::Foster => 'Foster',
            self::Guardian => 'Guardian',
            self::Unknown => 'Not sure',
        };
    }
}
