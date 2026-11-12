<?php

namespace App\Services\Reports;

use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\FamilyPhoto;
use App\Models\MemberName;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Genealogy\FamilyGraph;
use App\Services\Genealogy\FamilyGraphLoader;
use App\Services\Genealogy\RelativesPresenter;
use App\Services\History\TimelineBuilder;
use App\Services\Media\MediaLibrary;
use App\Services\Privacy\MemberPresenter;
use App\Services\Privacy\MemberPrivacy;
use App\Services\Privacy\MemberViewer;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Builds the HTML of a report, as the person who asked for it would see the family.
 *
 * Every person passes through the same privacy rules as on screen: someone the requester may
 * not see appears as "Private relative", and a living person's dates and places are left out
 * for roles that may not see them. A report can therefore never show more than the screen.
 */
class ReportBuilder
{
    public const TYPES = [
        'tree' => 'Family tree chart',
        'descendants' => 'Descendants',
        'ancestors' => 'Ancestors',
        'individual' => 'Individual summary',
        'directory' => 'Family directory',
        'timeline' => 'Family timeline',
    ];

    /** Reports about one person. */
    public const PERSONAL = ['descendants', 'ancestors', 'individual'];

    /** @var Collection<string, FamilyMember> */
    private Collection $people;

    private MemberViewer $viewer;

    public function __construct(
        private readonly MemberPrivacy $privacy,
        private readonly MemberPresenter $presenter,
        private readonly FamilyGraphLoader $graphs,
        private readonly RelativesPresenter $relatives,
        private readonly TimelineBuilder $timeline,
        private readonly TreeChart $chart,
        private readonly MediaLibrary $library,
    ) {
        $this->people = new Collection;
    }

    /**
     * @return array{html: string, footer: string, role: string, landscape: bool, paper: 'A4'|'A3'}
     *
     * @throws DomainException with a reason for the requester when the report cannot be made
     */
    public function build(ReportExport $report, User $requester): array
    {
        $family = $report->family()->firstOrFail();
        $this->viewer = $this->privacy->viewer($requester, $family);

        if ($this->viewer->role === null) {
            throw new DomainException(__('You no longer have access to this family.'));
        }

        $this->people = $this->privacy
            ->scopeVisible(FamilyMember::query()->inFamily($family), $this->viewer)
            ->with(['birthPlace', 'deathPlace'])
            ->get()
            ->keyBy('id');

        $parameters = $report->parameters;
        $generations = max(1, min((int) ($parameters['generations'] ?? 4), (int) config('reports.max_generations')));
        $subject = $this->subject($family, $report->type, $parameters['member_id'] ?? null);

        $data = match ($report->type) {
            'tree' => $this->tree($family, $subject, min($generations, (int) config('reports.max_chart_generations'))),
            'descendants' => $this->descendants($family, $subject, $generations),
            'ancestors' => $this->ancestors($family, $subject, $generations),
            'individual' => $this->individual($family, $subject),
            'directory' => $this->directory(),
            'timeline' => $this->timelineData($family, $parameters),
            default => throw new DomainException(__('This kind of report is not available.')),
        };

        $shared = [
            'family' => $family,
            'title' => $report->title,
            'generated' => now($requester->timezone)->translatedFormat('j F Y'),
            'requester' => $requester->name,
        ];

        return [
            'html' => view("reports.{$report->type}", [...$shared, ...$data])->render(),
            'footer' => view('reports.footer', $shared)->render(),
            'role' => $this->viewer->role->value,
            'landscape' => $report->type === 'tree',
            'paper' => $report->type === 'tree' && ($data['chart']['width'] ?? 0) > 1500 ? 'A3' : 'A4',
        ];
    }

    /** The title shown in the list of reports and on the first page. */
    public function title(Family $family, string $type, ?FamilyMember $subject): string
    {
        return match ($type) {
            'tree' => $subject === null ? __('Family tree of :family', ['family' => $family->name]) : __('Descendant chart of :name', ['name' => $subject->full_name]),
            'descendants' => __('Descendants of :name', ['name' => (string) $subject?->full_name]),
            'ancestors' => __('Ancestors of :name', ['name' => (string) $subject?->full_name]),
            'individual' => __('Summary of :name', ['name' => (string) $subject?->full_name]),
            'directory' => __('Directory of :family', ['family' => $family->name]),
            default => __('Timeline of :family', ['family' => $family->name]),
        };
    }

    private function subject(Family $family, string $type, mixed $id): ?FamilyMember
    {
        $subject = is_string($id) ? $this->people->get($id) : null;

        if ($subject === null && $type === 'tree') {
            $subject = $family->root_member_id === null ? null : $this->people->get($family->root_member_id);
        }

        if ($subject === null && ($type === 'tree' || in_array($type, self::PERSONAL, true))) {
            throw new DomainException(__('The person this report is about is no longer in the tree, or you may no longer see them.'));
        }

        return $subject;
    }

    /**
     * @return array<string, mixed>
     */
    private function tree(Family $family, ?FamilyMember $root, int $generations): array
    {
        $chart = $this->chart->draw($this->graphs->for($family), (string) $root?->id, $generations, function (string $id): array {
            $line = $this->line($id);

            return ['name' => $line['name'], 'detail' => $line['lifespan'], 'hidden' => $line['hidden']];
        });

        return ['chart' => $chart, 'root' => $root === null ? null : $this->line($root->id), 'generations' => $generations];
    }

    /**
     * An outline: each person, their partners, then their children indented below.
     *
     * @return array<string, mixed>
     */
    private function descendants(Family $family, ?FamilyMember $root, int $generations): array
    {
        $graph = $this->graphs->for($family);
        $rows = [];
        $seen = [];
        $limit = (int) config('reports.max_people');

        $walk = function (string $id, string $number, int $depth) use (&$walk, &$rows, &$seen, $graph, $generations, $limit): void {
            if (count($rows) >= $limit) {
                return;
            }

            $repeat = isset($seen[$id]);
            $seen[$id] = true;

            $rows[] = [
                'number' => $number,
                'depth' => $depth,
                'person' => $this->line($id),
                'partners' => array_map(fn (string $partner): array => $this->line($partner), $graph->partnerIds($id)),
                'repeat' => $repeat,
            ];

            if ($repeat || $depth + 1 >= $generations) {
                return;
            }

            foreach ($this->byBirth($graph->childIds($id)) as $index => $child) {
                $walk($child, $number.'.'.($index + 1), $depth + 1);
            }
        };

        $walk((string) $root?->id, '1', 0);

        return ['rows' => $rows, 'generations' => $generations, 'truncated' => count($rows) >= $limit];
    }

    /**
     * Ancestors numbered the usual way: the person is 1, a person's father is twice their
     * number and their mother twice plus one.
     *
     * @return array<string, mixed>
     */
    private function ancestors(Family $family, ?FamilyMember $subject, int $generations): array
    {
        $graph = $this->graphs->for($family);
        $current = [1 => (string) $subject?->id];
        $levels = [];
        /** @var array<string, int> $numbered person id => the first number they were given */
        $numbered = [(string) $subject?->id => 1];

        for ($generation = 1; $generation <= $generations && $current !== []; $generation++) {
            $next = [];

            foreach ($current as $number => $id) {
                [$father, $mother] = $this->parentsOf($graph, $id);

                foreach ([[$father, $number * 2], [$mother, $number * 2 + 1]] as [$link, $ahnentafel]) {
                    if ($link !== null) {
                        $sameAs = $numbered[$link['person']] ?? null;
                        $numbered[$link['person']] ??= $ahnentafel;

                        // Someone already listed (cousins who married) is not traced upwards twice.
                        if ($sameAs === null) {
                            $next[$ahnentafel] = $link['person'];
                        }

                        $levels[$generation][] = [
                            'same_as' => $sameAs,
                            'number' => $ahnentafel,
                            'person' => $this->line($link['person']),
                            'child' => $number,
                            'note' => in_array($link['subtype'], ['biological', 'unknown'], true) ? null : __($link['subtype']),
                        ];
                    }
                }
            }

            $current = $next;
        }

        return ['subject' => $this->line((string) $subject?->id), 'levels' => $levels, 'generations' => $generations];
    }

    /**
     * @return array<string, mixed>
     */
    private function individual(Family $family, ?FamilyMember $subject): array
    {
        /** @var FamilyMember $subject */
        $subject->load(['birthPlace', 'deathPlace', 'hometown']);

        $photo = null;
        $url = $this->presenter->summary($subject, $this->viewer)['photo_url'];

        // The picture is put into the page itself: the PDF engine loads nothing from outside.
        if ($url !== null && $subject->profile_photo_id !== null) {
            $key = FamilyPhoto::query()->inFamily($family)->find($subject->profile_photo_id)?->keyFor('display');
            $bytes = $key === null ? null : $this->library->disk()->get($key);
            $photo = $bytes === null ? null : 'data:image/jpeg;base64,'.base64_encode($bytes);
        }

        return [
            'person' => $this->presenter->profile($subject, $this->viewer),
            'photo' => $photo,
            'names' => $subject->names()->orderBy('created_at')->get()->map(fn (MemberName $name): array => ['name' => $name->name, 'type' => $name->type->label()])->all(),
            'relatives' => $this->relatives->for($family, $subject, $this->viewer),
            'events' => $this->timeline->build($family, $this->viewer, ['member' => $subject->id])['items'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function directory(): array
    {
        $rows = $this->people
            ->where('is_placeholder', false)
            ->sortBy(fn (FamilyMember $member): string => mb_strtolower($member->full_name))
            ->take((int) config('reports.max_people'))
            ->map(fn (FamilyMember $member): array => [...$this->line($member->id), 'generation' => $member->generation])
            ->values()
            ->all();

        return ['rows' => $rows];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function timelineData(Family $family, array $parameters): array
    {
        $from = is_numeric($parameters['from'] ?? null) ? (int) $parameters['from'] : null;
        $to = is_numeric($parameters['to'] ?? null) ? (int) $parameters['to'] : null;
        $built = $this->timeline->build($family, $this->viewer, ['from' => $from, 'to' => $to]);
        $decades = [];

        foreach ($built['items'] as $item) {
            $decades[$item['year'] === null ? __('Date not known') : (intdiv($item['year'], 10) * 10).'s'][] = $item;
        }

        return ['decades' => $decades, 'from' => $from, 'to' => $to, 'truncated' => $built['truncated']];
    }

    /**
     * One person as a report line, within what the requester may see.
     *
     * @return array{name: string, hidden: bool, lifespan: string|null, born: string|null, born_place: string|null, died: string|null, died_place: string|null, living: bool}
     */
    private function line(string $id): array
    {
        $member = $this->people->get($id);

        if ($member === null) {
            return ['name' => __('Private relative'), 'hidden' => true, 'lifespan' => null, 'born' => null, 'born_place' => null, 'died' => null, 'died_place' => null, 'living' => true];
        }

        $sensitive = $this->viewer->canViewSensitive($member);

        return [
            'name' => $member->full_name,
            'hidden' => false,
            'lifespan' => $sensitive ? MemberPresenter::lifespan($member) : null,
            'born' => $sensitive ? $member->birth->label() : null,
            'born_place' => $sensitive ? $member->birthPlace?->name : null,
            'died' => $member->death->label(),
            'died_place' => $member->deathPlace?->name,
            'living' => $member->isPresumedLiving(),
        ];
    }

    /**
     * The two parents a pedigree follows: by birth or adoption first, the father (or the
     * first recorded) in the first place.
     *
     * @return array{0: array{id: string, person: string, subtype: string, certainty: string}|null, 1: array{id: string, person: string, subtype: string, certainty: string}|null}
     */
    private function parentsOf(FamilyGraph $graph, string $id): array
    {
        $links = $graph->parentLinks($id);

        usort($links, fn (array $a, array $b): int => array_search($a['subtype'], ['biological', 'adoptive', 'unknown', 'step', 'foster', 'guardian'], true)
            <=> array_search($b['subtype'], ['biological', 'adoptive', 'unknown', 'step', 'foster', 'guardian'], true));

        $links = array_slice($links, 0, 2);
        $gender = fn (array $link): ?string => $this->people->get($link['person'])?->gender?->value;

        $father = $mother = null;

        foreach ($links as $link) {
            if ($gender($link) === 'female' && $mother === null) {
                $mother = $link;
            } elseif ($father === null) {
                $father = $link;
            } else {
                $mother ??= $link;
            }
        }

        return [$father, $mother];
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function byBirth(array $ids): array
    {
        usort($ids, fn (string $a, string $b): int => [(string) ($this->people->get($a)?->getRawOriginal('birth_date') ?? '9999'), $a]
            <=> [(string) ($this->people->get($b)?->getRawOriginal('birth_date') ?? '9999'), $b]);

        return $ids;
    }
}
