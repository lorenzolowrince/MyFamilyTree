<?php

namespace App\Enums;

enum NameType: string
{
    case Birth = 'birth';
    case Married = 'married';
    case Religious = 'religious';
    case Alias = 'alias';
    case Romanised = 'romanised';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Birth => 'Birth name',
            self::Married => 'Married name',
            self::Religious => 'Religious name',
            self::Alias => 'Also known as',
            self::Romanised => 'Romanised spelling',
            self::Other => 'Other name',
        };
    }
}
