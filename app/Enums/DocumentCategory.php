<?php

namespace App\Enums;

enum DocumentCategory: string
{
    case BirthCertificate = 'birth_certificate';
    case MarriageCertificate = 'marriage_certificate';
    case DeathCertificate = 'death_certificate';
    case Letter = 'letter';
    case LandRecord = 'land_record';
    case ReligiousRecord = 'religious_record';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::BirthCertificate => 'Birth certificate',
            self::MarriageCertificate => 'Marriage certificate',
            self::DeathCertificate => 'Death certificate',
            self::Letter => 'Letter',
            self::LandRecord => 'Land record',
            self::ReligiousRecord => 'Religious record',
            self::Other => 'Other',
        };
    }
}
