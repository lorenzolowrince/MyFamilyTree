<?php

namespace App\Console\Commands;

use App\Actions\Account\EraseAccount;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Runs daily: erases accounts whose deletion grace period has ended. */
#[Signature('accounts:erase-expired')]
#[Description('Erase accounts whose deletion grace period has passed')]
class EraseExpiredAccounts extends Command
{
    public function handle(EraseAccount $erase): int
    {
        $count = 0;

        User::query()
            ->whereNotNull('deletion_scheduled_at')
            ->where('deletion_scheduled_at', '<=', now())
            ->eachById(function (User $user) use ($erase, &$count): void {
                $erase->handle($user);
                $count++;
            });

        $this->info("Erased {$count} account(s).");

        return self::SUCCESS;
    }
}
