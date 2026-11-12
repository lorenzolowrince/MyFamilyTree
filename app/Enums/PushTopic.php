<?php

namespace App\Enums;

/**
 * Kinds of push notification a person can switch on or off (Settings → Notifications).
 * Email stays the main channel; push is an extra on devices where it was turned on.
 */
enum PushTopic: string
{
    /** Proposals waiting for you, and decisions on yours. */
    case Approvals = 'approvals';

    /** New family events, and a reminder the day before. */
    case Events = 'events';

    /** Birthdays and wedding anniversaries of living relatives, on the day. */
    case Celebrations = 'celebrations';

    public function label(): string
    {
        return match ($this) {
            self::Approvals => __('Approvals'),
            self::Events => __('Family events'),
            self::Celebrations => __('Birthdays and anniversaries'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Approvals => __('When a change is waiting for your approval, and when a change you proposed is decided.'),
            self::Events => __('When a family event is announced, and the day before it.'),
            self::Celebrations => __('In the morning, the birthdays and anniversaries of living relatives whose dates you can see.'),
        };
    }
}
