<?php

namespace App\Enums;

enum EventType: string
{
    case Reunion = 'reunion';
    case Celebration = 'celebration';
    case Memorial = 'memorial';
    case Announcement = 'announcement';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Reunion => 'Reunion',
            self::Celebration => 'Celebration',
            self::Memorial => 'Memorial',
            self::Announcement => 'Announcement',
            self::Other => 'Other',
        };
    }

    /** The timeline category an event of this type becomes once it has happened. */
    public function timelineCategory(): TimelineCategory
    {
        return match ($this) {
            self::Reunion, self::Celebration => TimelineCategory::Reunion,
            self::Memorial => TimelineCategory::Religious,
            default => TimelineCategory::Other,
        };
    }
}
