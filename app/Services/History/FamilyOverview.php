<?php

namespace App\Services\History;

use App\Enums\PartnershipStatus;
use App\Enums\RelationshipType;
use App\Models\Family;
use App\Models\FamilyEvent;
use App\Models\FamilyMember;
use App\Models\FamilyRelationship;
use App\Services\Privacy\MemberPresenter;
use App\Services\Privacy\MemberPrivacy;
use App\Services\Privacy\MemberViewer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard of one family: headline numbers, people added recently, and the birthdays,
 * anniversaries and events coming up. Birthdays and anniversaries involve living people's
 * exact dates, so they are only listed for viewers allowed to see those.
 */
class FamilyOverview
{
    public const DAYS_AHEAD = 60;

    public function __construct(
        private readonly MemberPrivacy $privacy,
        private readonly MemberPresenter $presenter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Family $family, MemberViewer $viewer, string $timezone): array
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();

        return [
            'family' => ['slug' => $family->slug, 'name' => $family->name],
            'stats' => $this->stats($family),
            'recent' => $this->privacy->scopeVisible(FamilyMember::query()->inFamily($family), $viewer)
                ->where('is_placeholder', false)
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn (FamilyMember $member): array => $this->presenter->summary($member, $viewer))
                ->all(),
            'birthdays' => $this->birthdays($family, $viewer, $today),
            'anniversaries' => $this->anniversaries($family, $viewer, $today),
            'events' => FamilyEvent::query()
                ->where('family_id', $family->id)
                ->where(fn ($query) => $query->where('starts_at', '>=', now())->orWhere('ends_at', '>=', now()))
                ->orderBy('starts_at')
                ->limit(5)
                ->get()
                ->map(fn (FamilyEvent $event): array => [
                    'id' => $event->id,
                    'title' => $event->title,
                    'type' => $event->type->label(),
                    'starts_at' => $event->starts_at->toIso8601String(),
                    'all_day' => $event->all_day,
                    'location' => $event->location_text,
                ])
                ->all(),
        ];
    }

    /**
     * @return array{members: int, living: int, deceased: int, generations: int, relationships: int}
     */
    private function stats(Family $family): array
    {
        $row = DB::table('family_members')
            ->where('family_id', $family->id)
            ->whereNull('deleted_at')
            ->where('is_placeholder', false)
            ->selectRaw("count(*) AS members, count(*) FILTER (WHERE living_status = 'living') AS living, count(*) FILTER (WHERE living_status = 'deceased') AS deceased, coalesce(max(generation), 0) AS generations")
            ->first();

        return [
            'members' => (int) ($row->members ?? 0),
            'living' => (int) ($row->living ?? 0),
            'deceased' => (int) ($row->deceased ?? 0),
            'generations' => (int) ($row->generations ?? 0),
            'relationships' => DB::table('family_relationships')->where('family_id', $family->id)->whereNull('deleted_at')->count(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function birthdays(Family $family, MemberViewer $viewer, CarbonImmutable $today): array
    {
        $members = $this->privacy->scopeVisible(FamilyMember::query()->inFamily($family), $viewer)
            ->where('living_status', '<>', 'deceased')
            ->whereNotNull('birth_month')
            ->get();

        $upcoming = [];

        foreach ($members as $member) {
            $birth = $member->birth->date;

            if ($birth === null || ! $viewer->canViewSensitive($member)) {
                continue;
            }

            $next = self::nextOccurrence($birth->month, $birth->day, $today);

            if ($today->diffInDays($next) > self::DAYS_AHEAD) {
                continue;
            }

            $upcoming[] = [
                'person' => $this->presenter->summary($member, $viewer),
                'date' => $next->toDateString(),
                'days' => (int) $today->diffInDays($next),
                'turning' => $next->year - $birth->year,
            ];
        }

        usort($upcoming, fn (array $x, array $y): int => $x['days'] <=> $y['days']);

        return array_slice($upcoming, 0, 8);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function anniversaries(Family $family, MemberViewer $viewer, CarbonImmutable $today): array
    {
        $relationships = FamilyRelationship::query()
            ->where('family_id', $family->id)
            ->where('type', RelationshipType::Partnership->value)
            ->where('status', PartnershipStatus::Current->value)
            ->where('start_date_precision', 'day')
            ->with(['personA', 'personB'])
            ->get();

        $upcoming = [];

        foreach ($relationships as $relationship) {
            $a = $relationship->personA;
            $b = $relationship->personB;
            $start = $relationship->start->date;

            if ($a === null || $b === null || $start === null
                || ! $a->isPresumedLiving() || ! $b->isPresumedLiving()
                || ! $viewer->canViewSensitive($a) || ! $viewer->canViewSensitive($b)) {
                continue;
            }

            $next = self::nextOccurrence($start->month, $start->day, $today);

            if ($today->diffInDays($next) > self::DAYS_AHEAD) {
                continue;
            }

            $upcoming[] = [
                'people' => [$this->presenter->summary($a, $viewer), $this->presenter->summary($b, $viewer)],
                'date' => $next->toDateString(),
                'days' => (int) $today->diffInDays($next),
                'years' => $next->year - $start->year,
            ];
        }

        usort($upcoming, fn (array $x, array $y): int => $x['days'] <=> $y['days']);

        return array_slice($upcoming, 0, 8);
    }

    /** The next time this month and day comes round, today included. 29 February falls on 28 February in other years. */
    public static function nextOccurrence(int $month, int $day, CarbonImmutable $today): CarbonImmutable
    {
        foreach ([$today->year, $today->year + 1] as $year) {
            $safeDay = min($day, CarbonImmutable::create($year, $month, 1, 0, 0, 0, $today->timezone)->daysInMonth);
            $date = CarbonImmutable::create($year, $month, $safeDay, 0, 0, 0, $today->timezone);

            if ($date !== null && $date->gte($today)) {
                return $date;
            }
        }

        return $today;
    }
}
