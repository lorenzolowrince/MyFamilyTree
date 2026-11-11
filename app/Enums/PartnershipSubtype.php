<?php

namespace App\Enums;

enum PartnershipSubtype: string
{
    case Marriage = 'marriage';
    case CustomaryMarriage = 'customary_marriage';
    case CivilUnion = 'civil_union';
    case Partner = 'partner';
    case Engaged = 'engaged';

    public function label(): string
    {
        return match ($this) {
            self::Marriage => 'Marriage',
            self::CustomaryMarriage => 'Customary marriage',
            self::CivilUnion => 'Civil union',
            self::Partner => 'Partner',
            self::Engaged => 'Engaged',
        };
    }
}
