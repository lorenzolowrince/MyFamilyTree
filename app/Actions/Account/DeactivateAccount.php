<?php

namespace App\Actions\Account;

use App\Enums\UserStatus;
use App\Models\User;

/**
 * Pauses an account at the user's request. Nothing is deleted; signing in again offers to
 * reactivate it.
 */
class DeactivateAccount
{
    public function __construct(private readonly RevokeSessions $revokeSessions) {}

    public function handle(User $user): void
    {
        $user->forceFill(['status' => UserStatus::Deactivated])->save();

        $this->revokeSessions->all($user);
    }
}
