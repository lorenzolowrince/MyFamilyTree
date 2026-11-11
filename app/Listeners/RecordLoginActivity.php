<?php

namespace App\Listeners;

use App\Enums\LoginEvent;
use App\Models\LoginActivity;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Fortify;

/**
 * Writes sign-in history to `login_activities` (docs/architecture/02-database.md).
 * Passwords, codes and tokens are never recorded.
 */
class RecordLoginActivity
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
                $this->record(LoginEvent::Login, $event->user);
            }
        });

        $events->listen(Logout::class, function (Logout $event): void {
            if ($event->user instanceof User) {
                $this->record(LoginEvent::Logout, $event->user);
            }
        });

        $events->listen(Failed::class, function (Failed $event): void {
            $email = $event->credentials[Fortify::username()] ?? null;

            $this->record(
                LoginEvent::Failed,
                $event->user instanceof User ? $event->user : null,
                is_string($email) ? $email : null,
            );
        });

        $events->listen(Lockout::class, function (Lockout $event): void {
            $email = $event->request->input(Fortify::username());

            $this->record(LoginEvent::Lockout, null, is_string($email) ? $email : null);
        });

        $events->listen(PasswordReset::class, function (PasswordReset $event): void {
            if ($event->user instanceof User) {
                $this->record(LoginEvent::PasswordReset, $event->user);
            }
        });

        $events->listen(TwoFactorAuthenticationFailed::class, function (TwoFactorAuthenticationFailed $event): void {
            $this->record(LoginEvent::MfaFailed, $event->user);
        });

        $events->listen(TwoFactorAuthenticationConfirmed::class, function (TwoFactorAuthenticationConfirmed $event): void {
            $this->record(LoginEvent::MfaEnabled, $event->user);
        });

        $events->listen(TwoFactorAuthenticationDisabled::class, function (TwoFactorAuthenticationDisabled $event): void {
            $this->record(LoginEvent::MfaDisabled, $event->user);
        });
    }

    public function record(LoginEvent $event, ?User $user, ?string $emailAttempted = null): void
    {
        $email = $emailAttempted ?? ($user === null ? '' : $user->email);

        LoginActivity::create([
            'user_id' => $user?->id,
            'email_attempted' => Str::limit($email, 255, '') ?: null,
            'event' => $event,
            'ip_address' => request()->ip(),
            'user_agent' => Str::limit((string) request()->userAgent(), 1000, '') ?: null,
        ]);
    }
}
