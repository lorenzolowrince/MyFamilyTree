<?php

namespace App\Notifications;

use App\Models\Family;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells a family's Owner and Administrators that someone accepted an invitation. */
class MemberJoinedFamily extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Family $family,
        public readonly string $newMemberName,
        public readonly string $roleLabel,
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
            ->subject(__(':name joined ":family"', ['name' => $this->newMemberName, 'family' => $this->family->name]))
            ->line(__(':name accepted an invitation and joined the ":family" family tree as :role.', [
                'name' => $this->newMemberName,
                'family' => $this->family->name,
                'role' => $this->roleLabel,
            ]))
            ->action(__('See who has access'), route('families.people.index', $this->family));
    }
}
