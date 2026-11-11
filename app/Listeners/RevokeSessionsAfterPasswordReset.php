<?php

namespace App\Listeners;

use App\Actions\Account\RevokeSessions;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;

/**
 * After a password reset every existing session is signed out, so whoever knew the old
 * password (or holds a stolen cookie) loses access (docs/architecture/06-security.md).
 */
class RevokeSessionsAfterPasswordReset
{
    public function __construct(private readonly RevokeSessions $revokeSessions) {}

    public function handle(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            $this->revokeSessions->all($event->user);
        }
    }
}
