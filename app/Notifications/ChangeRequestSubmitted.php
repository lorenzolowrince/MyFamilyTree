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

/** Tells a family's Owner and Administrators that a change is waiting for approval. */
class ChangeRequestSubmitted extends Notification implements ShouldQueue
{
    use Queueable, SendsWebPush;

    public function __construct(
        public readonly ChangeRequest $request,
        public readonly Family $family,
        public readonly string $submitterName,
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
        // No personal details in the email itself: the proposal is read after signing in.
        return (new MailMessage)
            ->subject(__('A change to ":family" is waiting for approval', ['family' => $this->family->name]))
            ->line(__(':name proposed a change to the ":family" family tree.', ['name' => $this->submitterName, 'family' => $this->family->name]))
            ->action(__('Review the proposal'), route('families.changes.index', $this->family))
            ->line(__('Nothing changes in the tree until someone approves it.'));
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return $this->pushMessage(
            $this->family->name,
            __('A change is waiting for your approval.'),
            route('families.changes.index', $this->family, false),
            'approvals-'.$this->family->id,
        );
    }
}
