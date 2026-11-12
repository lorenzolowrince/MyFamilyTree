<?php

namespace App\Notifications;

use App\Models\Family;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells someone with an account that they appear in a newly tagged photo. */
class TaggedInPhoto extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Family $family,
        public readonly string $photoId,
        public readonly ?string $taggedBy,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('You were tagged in a photo in ":family"', ['family' => $this->family->name]))
            ->line($this->taggedBy === null
                ? __('You were tagged in a photo in the ":family" family.', ['family' => $this->family->name])
                : __(':name tagged you in a photo in the ":family" family.', ['name' => $this->taggedBy, 'family' => $this->family->name]))
            ->action(__('See the photo'), route('families.photos.show', ['family' => $this->family, 'photo' => $this->photoId]));
    }
}
