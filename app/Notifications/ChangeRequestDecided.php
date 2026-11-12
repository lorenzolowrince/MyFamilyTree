<?php

namespace App\Notifications;

use App\Concerns\SendsWebPush;
use App\Enums\PushTopic;
use App\Models\ChangeRequest;
use App\Models\Family;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/** Tells the person who proposed a change whether it was approved or not. */
class ChangeRequestDecided extends Notification implements ShouldQueue
{
    use Queueable, SendsWebPush;

    public function __construct(
        public readonly ChangeRequest $request,
        public readonly Family $family,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->withPush($notifiable, PushTopic::Approvals);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $approved = $this->request->status === 'approved';

        $mail = (new MailMessage)
            ->subject($approved
                ? __('Your change to ":family" was approved', ['family' => $this->family->name])
                : __('Your change to ":family" was not approved', ['family' => $this->family->name]))
            ->line($approved
                ? __('Your proposed change to the ":family" family tree has been approved and applied.', ['family' => $this->family->name])
                : __('Your proposed change to the ":family" family tree was not approved.', ['family' => $this->family->name]));

        if ($this->request->review_note !== null) {
            $mail->line(__('Note from the reviewer: ":note"', ['note' => $this->request->review_note]));
        }

        return $mail->action(__('See your proposals'), route('families.changes.index', $this->family));
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return $this->pushMessage(
            $this->family->name,
            $this->request->status === 'approved'
                ? __('Your proposed change was approved.')
                : __('Your proposed change was not approved.'),
            route('families.changes.index', $this->family, false),
            'decided-'.$this->request->id,
        );
    }
}
