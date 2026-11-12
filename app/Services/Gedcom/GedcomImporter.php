<?php

namespace App\Services\Gedcom;

use App\Actions\Members\CreateMember;
use App\Actions\Members\FillsMemberAttributes;
use App\Actions\Relationships\LinkParentAndChild;
use App\Actions\Relationships\LinkPartners;
use App\Enums\Certainty;
use App\Enums\LivingStatus;
use App\Enums\NameType;
use App\Enums\ParentSubtype;
use App\Exceptions\InvalidRelationshipException;
use App\Jobs\RecomputeGenerations;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\GedcomImport;
use App\Models\MemberName;
use App\Models\User;
use App\Services\Audit\HistoryRecorder;
use App\Services\Genealogy\DuplicateDetector;
use App\Services\Media\MediaLibrary;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The two halves of a GEDCOM import.
 *
 * `prepare` reads the uploaded file and writes down what it contains and who in it may already
 * be in the tree. Nothing in the tree changes.
 *
 * `run` applies the file after a person has confirmed the preview, using the same actions as
 * manual edits, so an imported tree obeys every rule a hand-entered one does. A link the rules
 * refuse is skipped and reported; the rest of the import continues. Everything happens in one
 * transaction: an import either completes or leaves the tree as it was.
 *
 * @phpstan-import-type Document from GedcomReader
 */
class GedcomImporter
{
    use FillsMemberAttributes;

    public const MAX_PEOPLE = 5000;

    public const CREATE = 'create';

    public const SKIP = 'skip';

    public function __construct(
        private readonly GedcomReader $reader,
        private readonly MediaLibrary $library,
        private readonly DuplicateDetector $duplicates,
        private readonly HistoryRecorder $history,
    ) {}

    /** Read the file and store the preview. */
    public function prepare(GedcomImport $import): void
    {
        $import->forceFill(['status' => 'parsing'])->save();

        $family = $import->family()->firstOrFail();
        $document = $this->document($import);

        if (count($document['people']) > self::MAX_PEOPLE) {
            throw new DomainException(__('The file has :count people. At most :max can be imported at once; split it into branches in the program it came from.', [
                'count' => number_format(count($document['people'])), 'max' => number_format(self::MAX_PEOPLE),
            ]));
        }

        if ($document['people'] === []) {
            throw new DomainException(__('No people were found in the file. Is it a GEDCOM (.ged) file?'));
        }

        $hasMembers = FamilyMember::query()->inFamily($family)->exists();
        $people = [];
        $suggested = [];

        foreach ($document['people'] as $xref => $person) {
            $matches = [];

            if ($hasMembers && ! $person['placeholder']) {
                foreach ($this->duplicates->find($family, $person['name'], $person['birth_year'], 3) as $match) {
                    $matches[] = [
                        'id' => $match->id,
                        'name' => $match->full_name,
                        'birth_year' => $match->birth->yearValue(),
                        'death_year' => $match->death->yearValue(),
                    ];
                }

                // Same name and same birth year (or neither has one): almost certainly the same person.
                $best = $matches[0] ?? null;

                if ($best !== null && mb_strtolower($best['name']) === mb_strtolower($person['name']) && $best['birth_year'] === $person['birth_year']) {
                    $suggested[$xref] = $best['id'];
                }
            }

            $people[] = [
                'xref' => $xref,
                'name' => $person['name'],
                'birth_year' => $person['birth_year'],
                'death_year' => $person['death_year'],
                'gender' => $person['gender'],
                'matches' => $matches,
            ];
        }

        $import->forceFill([
            'status' => 'preview_ready',
            'summary' => [
                'counts' => [
                    'people' => count($document['people']),
                    'partnerships' => count($document['partnerships']),
                    'parent_links' => count($document['parents']),
                    'possible_duplicates' => count(array_filter($people, fn (array $person): bool => $person['matches'] !== [])),
                ],
                'version' => $document['version'],
                'source' => $document['source'],
                'warnings' => $document['warnings'],
                'people' => $people,
                'suggested' => $suggested,
            ],
        ])->save();
    }

    /**
     * Apply the file to the tree.
     *
     * @throws DomainException when the import cannot start at all
     */
    public function run(GedcomImport $import, User $actor): void
    {
        $family = $import->family()->firstOrFail();
        $document = $this->document($import);
        $mapping = $import->mapping;

        $result = DB::transaction(fn (): array => $this->history->quietly(fn (): array => $this->apply($family, $actor, $document, $mapping)));

        $import->forceFill(['status' => 'completed', 'result' => $result])->save();
        $this->library->disk()->delete($import->storage_key);

        RecomputeGenerations::dispatchSync($family->id);

        // One line in each history instead of thousands.
        $this->history->audit('gedcom.imported', $family->id, 'gedcom_import', $import->id, null, [
            'filename' => $import->original_filename,
            ...array_diff_key($result, ['problems' => true]),
        ], $actor);

        $this->history->activity(
            $family->id,
            'gedcom.imported',
            trans_choice('{1} imported 1 person from a GEDCOM file|[2,*] imported :count people from a GEDCOM file', $result['created'] + $result['merged'], ['count' => $result['created'] + $result['merged']]),
            'gedcom_import',
            $import->id,
            'family',
            $actor,
        );
    }

    /**
     * @param  Document  $document
     * @param  array<string, string>  $mapping  xref => 'create', 'skip' or the id of an existing person
     * @return array{created: int, merged: int, skipped: int, partnerships: int, parent_links: int, problems: list<string>}
     */
    private function apply(Family $family, User $actor, array $document, array $mapping): array
    {
        $result = ['created' => 0, 'merged' => 0, 'skipped' => 0, 'partnerships' => 0, 'parent_links' => 0, 'problems' => []];
        $create = app(CreateMember::class);

        /** @var array<string, FamilyMember> $members xref => the person in the tree */
        $members = [];
        $used = [];

        foreach ($document['people'] as $xref => $person) {
            $decision = $mapping[$xref] ?? self::CREATE;

            if ($decision === self::SKIP) {
                $result['skipped']++;

                continue;
            }

            if ($decision !== self::CREATE) {
                $existing = FamilyMember::query()->inFamily($family)->find($decision);

                // Two people in the file cannot both become the same person in the tree.
                if ($existing !== null && ! isset($used[$existing->id])) {
                    $used[$existing->id] = true;
                    $this->mergeInto($existing, $family, $actor, $person['data']);
                    $this->addNames($existing, $person['names']);
                    $members[$xref] = $existing;
                    $result['merged']++;

                    continue;
                }
            }

            $member = $create->handle($family, $actor, $person['data'], $person['placeholder']);
            $this->addNames($member, $person['names']);
            $members[$xref] = $member;
            $result['created']++;
        }

        $existingLinks = DB::table('family_relationships')
            ->where('family_id', $family->id)
            ->whereNull('deleted_at')
            ->get(['type', 'person_a_id', 'person_b_id'])
            ->mapWithKeys(fn (\stdClass $row): array => ["{$row->type}|{$row->person_a_id}|{$row->person_b_id}" => true])
            ->all();

        $partners = app(LinkPartners::class);

        foreach ($document['partnerships'] as $link) {
            $one = $members[$link['a']] ?? null;
            $other = $members[$link['b']] ?? null;

            if ($one === null || $other === null) {
                continue;
            }

            [$a, $b] = strcmp($one->id, $other->id) < 0 ? [$one->id, $other->id] : [$other->id, $one->id];

            if (isset($existingLinks["partnership|{$a}|{$b}"])) {
                continue;
            }

            try {
                $partners->handle($family, $actor, $one, $other, $link['details']);
                $existingLinks["partnership|{$a}|{$b}"] = true;
                $result['partnerships']++;
            } catch (InvalidRelationshipException $exception) {
                self::problem($result, __(':a and :b were not linked as partners: :reason', ['a' => $one->full_name, 'b' => $other->full_name, 'reason' => $exception->getMessage()]));
            }
        }

        $parents = app(LinkParentAndChild::class);

        foreach ($document['parents'] as $link) {
            $parent = $members[$link['parent']] ?? null;
            $child = $members[$link['child']] ?? null;

            if ($parent === null || $child === null || isset($existingLinks["parent_child|{$parent->id}|{$child->id}"])) {
                continue;
            }

            try {
                $parents->handle($family, $actor, $parent, $child, ParentSubtype::from($link['subtype']), Certainty::from($link['certainty']));
                $existingLinks["parent_child|{$parent->id}|{$child->id}"] = true;
                $result['parent_links']++;
            } catch (InvalidRelationshipException $exception) {
                self::problem($result, __(':parent was not linked as a parent of :child: :reason', ['parent' => $parent->full_name, 'child' => $child->full_name, 'reason' => $exception->getMessage()]));
            }
        }

        return $result;
    }

    /**
     * Add what the file knows to someone already in the tree. What is already recorded always
     * wins: an import never overwrites a family's own work.
     *
     * @param  array<string, mixed>  $data
     */
    private function mergeInto(FamilyMember $member, Family $family, User $actor, array $data): void
    {
        $fill = [];

        foreach (['given_names', 'surname', 'maiden_name', 'nickname', 'occupation', 'education', 'nationality', 'biography', 'notes'] as $field) {
            if (isset($data[$field]) && ($member->{$field} === null || $member->{$field} === '')) {
                $fill[$field] = $data[$field];
            }
        }

        if (isset($data['gender']) && $member->gender === null) {
            $fill['gender'] = $data['gender'];
        }

        if (isset($data['birth']) && $member->birth->isEmpty()) {
            $fill['birth'] = $data['birth'];
        }

        if (($data['living_status'] ?? null) === 'deceased' && $member->living_status !== LivingStatus::Living) {
            $fill['living_status'] = 'deceased';

            if (isset($data['death']) && $member->death->isEmpty()) {
                $fill['death'] = $data['death'];
            }
        } elseif (($data['living_status'] ?? null) === 'living' && $member->living_status === LivingStatus::Unknown) {
            $fill['living_status'] = 'living';
        }

        foreach (['birth_place' => 'birth_place_id', 'death_place' => 'death_place_id', 'hometown' => 'hometown_place_id'] as $field => $column) {
            if (isset($data[$field]) && $member->{$column} === null) {
                $fill[$field] = $data[$field];
            }
        }

        if ($fill === []) {
            return;
        }

        $this->fillMember($member, $family, $fill);
        $member->updated_by = $actor->id;
        $member->save();
    }

    /**
     * @param  list<array{type: string, name: string, notes: string|null}>  $names
     */
    private function addNames(FamilyMember $member, array $names): void
    {
        $known = $member->names()->pluck('name')->map(fn (mixed $name): string => mb_strtolower((string) $name))->all();

        foreach ($names as $entry) {
            if (in_array(mb_strtolower($entry['name']), $known, true)) {
                continue;
            }

            $name = new MemberName;
            $name->family_id = $member->family_id;
            $name->member_id = $member->id;
            $name->type = NameType::tryFrom($entry['type']) ?? NameType::Other;
            $name->name = mb_substr($entry['name'], 0, 200);
            $name->notes = $entry['notes'];
            $name->save();

            $known[] = mb_strtolower($entry['name']);
        }
    }

    /**
     * @return Document
     */
    private function document(GedcomImport $import): array
    {
        $text = $this->library->disk()->get($import->storage_key);

        if ($text === null) {
            throw new DomainException(__('The uploaded file is no longer available. Please upload it again.'));
        }

        return $this->reader->read($text);
    }

    /**
     * @param  array{created: int, merged: int, skipped: int, partnerships: int, parent_links: int, problems: list<string>}  $result
     */
    private static function problem(array &$result, string $message): void
    {
        if (count($result['problems']) < 200) {
            $result['problems'][] = $message;
        }
    }
}
