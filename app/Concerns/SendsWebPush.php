<?php

namespace App\Concerns;

use App\Enums\PushTopic;
use App\Models\User;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * For notifications that can also arrive as a push message.
 *
 * A push message can appear on a locked phone, so its text names the family and what
 * happened, never anyone's private details.
 */
trait SendsWebPush
{
    /**
     * @param  list<string>  $channels  the channels used anyway (usually mail)
     * @return list<string>
     */
    protected function withPush(object $notifiable, PushTopic $topic, array $channels = ['mail']): array
    {
        if ($notifiable instanceof User && $notifiable->wantsPush($topic)) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    protected function pushMessage(string $title, string $body, string $url, string $tag): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($title)
            ->body($body)
            ->tag($tag)
            ->data(['url' => $url])
            ->options(['TTL' => (int) config('webpush.ttl', 86400), 'urgency' => 'normal']);
    }
}
