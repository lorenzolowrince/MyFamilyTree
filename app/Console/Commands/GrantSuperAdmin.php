<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The only way to make (or unmake) a Super Administrator. There is deliberately no screen
 * for this: it needs shell access to the server (docs/architecture/02-database.md, `users`).
 */
#[Signature('admin:grant {email : Email address of an existing, verified account} {--revoke : Remove Super Administrator access instead}')]
#[Description('Grant or revoke Super Administrator access for an account')]
class GrantSuperAdmin extends Command
{
    public function handle(): int
    {
        $user = User::query()->where('email', (string) $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No account uses that email address.');

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            $user->forceFill(['is_super_admin' => false])->save();
            $this->info("{$user->email} is no longer a Super Administrator.");

            return self::SUCCESS;
        }

        if (! $user->hasVerifiedEmail() || ! $user->isActive()) {
            $this->error('The account must be active and have a verified email address.');

            return self::FAILURE;
        }

        $user->forceFill(['is_super_admin' => true])->save();
        $this->info("{$user->email} is now a Super Administrator.");

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            $this->warn('They must turn on two-factor authentication before the admin area opens for them.');
        }

        return self::SUCCESS;
    }
}
