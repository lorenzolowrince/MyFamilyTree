<?php

namespace App\Actions\Admin;

use App\Actions\Account\RevokeSessions;
use App\Enums\UserStatus;
use App\Models\User;

/** Blocks an account (platform staff only) and signs it out everywhere. */
class SuspendUser
{
    public function __construct(private readonly RevokeSessions $revokeSessions) {}

    public function handle(User $account): void
    {
        $account->forceFill(['status' => UserStatus::Suspended])->save();

        $this->revokeSessions->all($account);
    }
}
