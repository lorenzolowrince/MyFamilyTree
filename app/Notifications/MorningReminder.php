<?php

namespace App\Notifications;

use App\Concerns\SendsWebPush;
use App\Models\Family;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * One push message per family in the morning: today's birthdays and anniversaries, and
 * tomorrow's events. Push only; built by SendMorningReminders with the recipient's own
 * privacy rules and chosen topics.
 */
class MorningReminder extends Notification implements ShouldQueue
{
    use Queueable, SendsWebPush;

    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public readonly Family $family,
        public readonly array $lines,
        public readonly string $date,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return $this->pushMessage(
            $this->family->name,
            implode(' ', $this->lines),
            route('families.show', $this->family, false),
            "morning-{$this->family->id}-{$this->date}",
        );
    }
}
