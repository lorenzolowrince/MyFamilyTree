<?php

namespace App\Notifications;

use App\Concerns\SendsWebPush;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/** "Send a test" on Settings → Notifications. Sent at once, not queued, so the result is immediate. */
class TestPush extends Notification
{
    use SendsWebPush;

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
            (string) config('app.name'),
            __('Push notifications work on this device.'),
            route('notifications.edit', absolute: false),
            'test',
        );
    }
}
