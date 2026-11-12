<?php

namespace App\Notifications;

use App\Concerns\SendsWebPush;
use App\Enums\PushTopic;
use App\Models\Family;
use App\Models\FamilyEvent;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A family event was added with "Let the family know" ticked (`family_events.notify_members`).
 * Everyone in the family can see events, so its title, time and place may be sent.
 */
class FamilyEventAnnounced extends Notification implements ShouldQueue
{
    use Queueable, SendsWebPush;

    public function __construct(
        public readonly FamilyEvent $event,
        public readonly Family $family,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->withPush($notifiable, PushTopic::Events);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('New event in ":family": :title', ['family' => $this->family->name, 'title' => $this->event->title]))
            ->line(__('A new event was added to the ":family" family calendar.', ['family' => $this->family->name]))
            ->line($this->event->title)
            ->line(self::when($this->event, $notifiable));

        if ($this->event->location_text !== null) {
            $mail->line(__('Where: :place', ['place' => $this->event->location_text]));
        }

        return $mail->action(__('See the event'), route('families.events.index', $this->family));
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return $this->pushMessage(
            $this->family->name,
            __('New event: :title, :when', ['title' => $this->event->title, 'when' => self::when($this->event, $notifiable)]),
            route('families.events.index', $this->family, false),
            'event-'.$this->event->id,
        );
    }

    /** The start, in the recipient's own time zone. */
    public static function when(FamilyEvent $event, object $notifiable): string
    {
        $timezone = $notifiable instanceof User ? $notifiable->timezone : config('app.timezone');
        $start = $event->starts_at->copy()->setTimezone($timezone);

        return $event->all_day ? $start->isoFormat('dddd D MMMM YYYY') : $start->isoFormat('dddd D MMMM YYYY, h:mm A');
    }
}
