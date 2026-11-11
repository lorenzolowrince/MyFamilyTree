<?php

namespace App\Enums;

enum PartnershipStatus: string
{
    case Current = 'current';
    case Divorced = 'divorced';
    case Separated = 'separated';
    case Widowed = 'widowed';
    case Annulled = 'annulled';

    public function label(): string
    {
        return match ($this) {
            self::Current => 'Current',
            self::Divorced => 'Divorced',
            self::Separated => 'Separated',
            self::Widowed => 'Widowed',
            self::Annulled => 'Annulled',
        };
    }
}
