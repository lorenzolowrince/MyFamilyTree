<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The invitation email. It carries the only copy of the token; the database keeps a hash.
 * The payload is encrypted on the queue for that reason.
 */
class FamilyInvitationSent extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $shouldBeEncrypted = true;

    public function __construct(
        public readonly string $familyName,
        public readonly string $inviterName,
        public readonly string $roleLabel,
        public readonly string $url,
        public readonly string $expires,
        public readonly ?string $personalMessage,
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
        $mail = (new MailMessage)
            ->subject(__(':inviter invited you to the :family family tree', ['inviter' => $this->inviterName, 'family' => $this->familyName]))
            ->greeting(__('You are invited'))
            ->line(__(':inviter has invited you to join ":family" on :app as :role.', [
                'inviter' => $this->inviterName,
                'family' => $this->familyName,
                'app' => config('app.name'),
                'role' => $this->roleLabel,
            ]));

        if ($this->personalMessage !== null) {
            $mail->line(__('Their message: ":message"', ['message' => $this->personalMessage]));
        }

        return $mail
            ->action(__('View invitation'), $this->url)
            ->line(__('This invitation expires on :date. Sign in or create an account with this email address to accept it.', ['date' => $this->expires]))
            ->line(__('If you were not expecting this, you can ignore this email.'));
    }
}
