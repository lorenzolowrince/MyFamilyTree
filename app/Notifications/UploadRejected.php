<?php

namespace App\Notifications;

use App\Models\Family;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells an uploader that their file was refused, and why. */
class UploadRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Family $family,
        public readonly string $filename,
        public readonly string $reason,
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
            ->subject(__('Your upload to ":family" was not accepted', ['family' => $this->family->name]))
            ->line(__('The file ":file" you uploaded to the ":family" family was not added.', ['file' => $this->filename, 'family' => $this->family->name]))
            ->line($this->reason)
            ->line(__('The file has been deleted from our servers.'));
    }
}
