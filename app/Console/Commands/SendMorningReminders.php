<?php

namespace App\Console\Commands;

use App\Enums\MembershipStatus;
use App\Enums\PushTopic;
use App\Enums\UserStatus;
use App\Models\Family;
use App\Models\FamilyEvent;
use App\Models\User;
use App\Notifications\MorningReminder;
use App\Services\History\FamilyOverview;
use App\Services\Privacy\MemberPrivacy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Runs every hour. People for whom it is now the morning hour in their own time zone get
 * one push message per family: today's birthdays and anniversaries (only those whose dates
 * they may see), and events starting tomorrow that were announced to the family.
 */
#[Signature('notifications:morning {--force : Send now, whatever the local hour}')]
#[Description('Send the morning push reminders (birthdays, anniversaries, events)')]
class SendMorningReminders extends Command
{
    public const HOUR = 8;

    public function handle(FamilyOverview $overview, MemberPrivacy $privacy): int
    {
        $sent = 0;

        User::query()
            ->where('status', UserStatus::Active->value)
            ->whereHas('pushSubscriptions')
            ->with(['families' => fn ($query) => $query->wherePivot('status', MembershipStatus::Active->value)])
            ->chunkById(200, function ($users) use ($overview, $privacy, &$sent): void {
                foreach ($users as $user) {
                    $sent += $this->remind($user, $overview, $privacy);
                }
            });

        $this->info("Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }

    private function remind(User $user, FamilyOverview $overview, MemberPrivacy $privacy): int
    {
        $now = CarbonImmutable::now($user->timezone);

        if (! $this->option('force') && $now->hour !== self::HOUR) {
            return 0;
        }

        $topics = $user->pushTopics();
        $wantsCelebrations = in_array(PushTopic::Celebrations, $topics, true);
        $wantsEvents = in_array(PushTopic::Events, $topics, true);

        if (! $wantsCelebrations && ! $wantsEvents) {
            return 0;
        }

        $sent = 0;

        /** @var Family $family */
        foreach ($user->families as $family) {
            // Once per person, family and day, even if the command runs twice.
            if (! Cache::add("push-morning:{$user->id}:{$family->id}:{$now->toDateString()}", true, now()->addDays(2))) {
                continue;
            }

            $lines = [];

            if ($wantsCelebrations) {
                $today = $overview->celebrationsToday($family, $privacy->viewer($user, $family), $user->timezone);

                if ($today['birthdays'] !== []) {
                    $lines[] = __('Birthday today: :names.', ['names' => implode(', ', $today['birthdays'])]);
                }

                if ($today['anniversaries'] !== []) {
                    $lines[] = __('Anniversary today: :names.', ['names' => implode(', ', $today['anniversaries'])]);
                }
            }

            if ($wantsEvents) {
                $tomorrow = $now->addDay()->startOfDay();
                $titles = FamilyEvent::query()
                    ->where('family_id', $family->id)
                    ->where('notify_members', true)
                    ->whereBetween('starts_at', [$tomorrow->utc(), $tomorrow->endOfDay()->utc()])
                    ->orderBy('starts_at')
                    ->pluck('title')
                    ->all();

                if ($titles !== []) {
                    $lines[] = __('Tomorrow: :titles.', ['titles' => implode(', ', $titles)]);
                }
            }

            if ($lines !== []) {
                $user->notify(new MorningReminder($family, $lines, $now->toDateString()));
                $sent++;
            }
        }

        return $sent;
    }
}
