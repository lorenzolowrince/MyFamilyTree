<?php

namespace App\Enums;

enum TimelineCategory: string
{
    case Education = 'education';
    case Career = 'career';
    case Migration = 'migration';
    case Reunion = 'reunion';
    case Military = 'military';
    case Religious = 'religious';
    case Residence = 'residence';
    case Achievement = 'achievement';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Education => 'Education',
            self::Career => 'Work and career',
            self::Migration => 'Migration',
            self::Reunion => 'Reunion or gathering',
            self::Military => 'Military service',
            self::Religious => 'Religious milestone',
            self::Residence => 'Home and residence',
            self::Achievement => 'Achievement',
            self::Other => 'Other',
        };
    }
}
