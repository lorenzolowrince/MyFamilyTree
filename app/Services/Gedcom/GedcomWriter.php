<?php

namespace App\Services\Gedcom;

use App\Enums\RelationshipType;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\FamilyRelationship;
use App\Models\MemberName;
use App\Models\Place;
use App\Services\Privacy\MemberViewer;
use App\Support\FuzzyDate\FuzzyDate;

/**
 * Writes a family tree as GEDCOM 7.0 text.
 *
 * GEDCOM groups people into "families" (a couple and their children); we store each link on
 * its own. So: every partnership becomes a FAM record; a child is listed under the FAM of two
 * of its parents who are partners, and under a one-parent FAM for any other parent. How the
 * child is related to each parent is written next to the child (`_FREL` to the HUSB, `_MREL`
 * to the WIFE, as several genealogy programs do), because GEDCOM's own PEDI cannot say
 * "born to the father, adopted by the mother".
 *
 * Only people the exporting person may see are written. Tags starting with `_` are our own
 * additions, declared in the header; other programs ignore them.
 *
 * @phpstan-type FamilyRecord array{xref: string, husband: string|null, wife: string|null, partnership: FamilyRelationship|null, children: list<array{id: string, father: FamilyRelationship|null, mother: FamilyRelationship|null}>}
 */
class GedcomWriter
{
    public const EXTENSIONS = ['_LIVING', '_PLACEHOLDER', '_RESEARCH', '_SUBTYPE', '_STATUS', '_END', '_SEQ', '_FREL', '_MREL', '_FCERT', '_MCERT'];

    private const SEX = ['male' => 'M', 'female' => 'F', 'other' => 'X', 'unknown' => 'U'];

    private const NAME_TYPES = ['birth' => 'BIRTH', 'married' => 'MARRIED', 'alias' => 'AKA', 'religious' => 'OTHER', 'romanised' => 'OTHER', 'other' => 'OTHER'];

    /** @var list<string> */
    private array $lines = [];

    /** @var array<string, string> place id => name */
    private array $places = [];

    public function write(Family $family, MemberViewer $viewer): string
    {
        $this->lines = [];
        $this->places = Place::query()->where('family_id', $family->id)->pluck('name', 'id')->all();

        $members = FamilyMember::query()
            ->inFamily($family)
            ->with('names')
            ->orderByRaw('generation NULLS LAST')
            ->orderByRaw('birth_date NULLS LAST')
            ->orderBy('full_name')
            ->orderBy('id')
            ->get()
            ->filter(fn (FamilyMember $member): bool => $viewer->canView($member))
            ->values();

        /** @var array<string, string> $xrefs member id => "@I1@" */
        $xrefs = [];

        foreach ($members as $index => $member) {
            $xrefs[$member->id] = '@I'.($index + 1).'@';
        }

        $relationships = FamilyRelationship::query()
            ->where('family_id', $family->id)
            ->orderBy('sequence')
            ->orderBy('start_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (FamilyRelationship $link): bool => isset($xrefs[$link->person_a_id], $xrefs[$link->person_b_id]));

        $genders = $members->mapWithKeys(fn (FamilyMember $member): array => [$member->id => $member->gender?->value])->all();
        $families = $this->families($relationships->all(), $xrefs, $genders);

        $this->line(0, 'HEAD');
        $this->line(1, 'GEDC');
        $this->line(2, 'VERS', '7.0');
        $this->line(1, 'SOUR', 'MYFAMILYTREE');
        $this->line(2, 'NAME', (string) config('app.name'));
        $this->line(1, 'DATE', strtoupper(now()->format('j M Y')));
        $this->line(1, 'SCHMA');

        foreach (self::EXTENSIONS as $tag) {
            $this->line(2, 'TAG', "{$tag} https://myfamilytree.app/gedcom/".strtolower(ltrim($tag, '_')));
        }

        foreach ($members as $member) {
            $this->individual($member, $xrefs[$member->id], $families, $viewer);
        }

        foreach ($families as $record) {
            $this->family($record, $xrefs);
        }

        $this->line(0, 'TRLR');

        return implode("\n", $this->lines)."\n";
    }

    /**
     * Group the links into GEDCOM families.
     *
     * @param  array<int, FamilyRelationship>  $relationships
     * @param  array<string, string>  $xrefs
     * @param  array<string, string|null>  $genders
     * @return list<FamilyRecord>
     */
    private function families(array $relationships, array $xrefs, array $genders): array
    {
        /** @var list<FamilyRecord> $families */
        $families = [];
        /** @var array<string, int> $couples "idA|idB" => index */
        $couples = [];
        /** @var array<string, int> $singles parent id => index */
        $singles = [];
        /** @var array<string, array<string, FamilyRelationship>> $parents child id => [parent id => link] */
        $parents = [];
        /** @var array<int, list<array{id: string, father: FamilyRelationship|null, mother: FamilyRelationship|null}>> $children family index => children */
        $children = [];

        foreach ($relationships as $link) {
            if ($link->type === RelationshipType::ParentChild) {
                $parents[$link->person_b_id][$link->person_a_id] = $link;

                continue;
            }

            [$husband, $wife] = $this->roles($link->person_a_id, $link->person_b_id, $genders);
            $key = $link->person_a_id.'|'.$link->person_b_id;

            // Two people are one family, however many times it was recorded.
            if (! isset($couples[$key])) {
                $couples[$key] = count($families);
                $families[] = self::record($husband, $wife, $link);
            }
        }

        // Children in the order of the individuals, so the output is stable.
        foreach (array_keys($xrefs) as $child) {
            $links = $parents[$child] ?? [];

            while ($links !== []) {
                $pair = null;

                foreach (array_keys($links) as $one) {
                    foreach (array_keys($links) as $other) {
                        $key = strcmp($one, $other) < 0 ? "{$one}|{$other}" : "{$other}|{$one}";

                        if ($one !== $other && isset($couples[$key])) {
                            // Prefer the couple the child was born or adopted to over a step link.
                            $same = $links[$one]->subtype === $links[$other]->subtype ? 0 : 1;

                            if ($pair === null || $same < $pair[1]) {
                                $pair = [$couples[$key], $same];
                            }
                        }
                    }
                }

                if ($pair !== null) {
                    $index = $pair[0];
                    $husband = $families[$index]['husband'];
                    $wife = $families[$index]['wife'];
                    $children[$index][] = ['id' => $child, 'father' => $links[$husband] ?? null, 'mother' => $links[$wife] ?? null];
                    unset($links[$husband], $links[$wife]);

                    continue;
                }

                // No partner of theirs is also a parent: a family of one parent.
                $parent = (string) array_key_first($links);

                if (! isset($singles[$parent])) {
                    $singles[$parent] = count($families);
                    $isWife = ($genders[$parent] ?? null) === 'female';
                    $families[] = self::record($isWife ? null : $parent, $isWife ? $parent : null, null);
                }

                $index = $singles[$parent];
                $children[$index][] = [
                    'id' => $child,
                    'father' => $families[$index]['husband'] === null ? null : $links[$parent],
                    'mother' => $families[$index]['wife'] === null ? null : $links[$parent],
                ];
                unset($links[$parent]);
            }
        }

        $numbered = [];

        foreach ($families as $index => $record) {
            $numbered[] = [...$record, 'xref' => '@F'.($index + 1).'@', 'children' => $children[$index] ?? []];
        }

        return $numbered;
    }

    /**
     * @return FamilyRecord
     */
    private static function record(?string $husband, ?string $wife, ?FamilyRelationship $partnership): array
    {
        return ['xref' => '', 'husband' => $husband, 'wife' => $wife, 'partnership' => $partnership, 'children' => []];
    }

    /**
     * @param  list<FamilyRecord>  $families
     */
    private function individual(FamilyMember $member, string $xref, array $families, MemberViewer $viewer): void
    {
        $this->line(0, 'INDI', '', $xref);

        $this->line(1, 'NAME', self::nameValue($member->full_name, $member->surname));
        $this->optional(2, 'GIVN', $member->given_names);
        $this->optional(2, 'SURN', $member->surname);
        $this->optional(2, 'NICK', $member->nickname);

        if ($member->maiden_name !== null) {
            $this->line(1, 'NAME', $member->maiden_name);
            $this->line(2, 'TYPE', 'MAIDEN');
        }

        /** @var MemberName $name */
        foreach ($member->names->sortBy([['created_at', 'asc'], ['id', 'asc']]) as $name) {
            $this->line(1, 'NAME', $name->name);
            $this->line(2, 'TYPE', self::NAME_TYPES[$name->type->value]);

            if (in_array($name->type->value, ['religious', 'romanised'], true)) {
                $this->line(3, 'PHRASE', $name->type->value);
            }

            $this->optional(2, 'NOTE', $name->notes);
        }

        $this->line(1, 'SEX', self::SEX[$member->gender->value ?? 'unknown']);

        $sensitive = $viewer->canViewSensitive($member);

        if ($sensitive) {
            $this->event('BIRT', $member->birth, $member->birth_place_id);
        }

        if ($member->living_status->value === 'deceased') {
            $this->event('DEAT', $member->death, $member->death_place_id, always: true);
        } elseif ($member->living_status->value === 'living') {
            $this->line(1, '_LIVING', 'Y');
        }

        if ($sensitive) {
            if ($member->hometown_place_id !== null && isset($this->places[$member->hometown_place_id])) {
                $this->line(1, 'RESI');
                $this->line(2, 'PLAC', $this->places[$member->hometown_place_id]);
            }

            $this->optional(1, 'OCCU', $member->occupation);
            $this->optional(1, 'EDUC', $member->education);
            $this->optional(1, 'NATI', $member->nationality);
            $this->optional(1, 'NOTE', $member->biography);
        }

        if ($viewer->canViewNotes($member)) {
            $this->optional(1, '_RESEARCH', $member->notes);
        }

        if ($member->visibility->value !== 'family') {
            $this->line(1, 'RESN', $member->visibility->value === 'private' ? 'PRIVACY' : 'CONFIDENTIAL');
        }

        if ($member->is_placeholder) {
            $this->line(1, '_PLACEHOLDER', 'Y');
        }

        foreach ($families as $record) {
            foreach ($record['children'] as $child) {
                if ($child['id'] === $member->id) {
                    $this->line(1, 'FAMC', $record['xref']);
                    $this->line(2, 'PEDI', self::pedigree(array_filter([$child['father']?->subtype, $child['mother']?->subtype])));
                }
            }
        }

        foreach ($families as $record) {
            if ($record['husband'] === $member->id || $record['wife'] === $member->id) {
                $this->line(1, 'FAMS', $record['xref']);
            }
        }
    }

    /**
     * @param  FamilyRecord  $record
     * @param  array<string, string>  $xrefs
     */
    private function family(array $record, array $xrefs): void
    {
        $this->line(0, 'FAM', '', $record['xref']);

        if ($record['husband'] !== null) {
            $this->line(1, 'HUSB', $xrefs[$record['husband']]);
        }

        if ($record['wife'] !== null) {
            $this->line(1, 'WIFE', $xrefs[$record['wife']]);
        }

        foreach ($record['children'] as $child) {
            $this->line(1, 'CHIL', $xrefs[$child['id']]);

            foreach (['F' => $child['father'], 'M' => $child['mother']] as $side => $link) {
                if ($link !== null) {
                    $this->line(2, "_{$side}REL", $link->subtype);

                    if ($link->certainty->value !== 'confirmed') {
                        $this->line(2, "_{$side}CERT", $link->certainty->value);
                    }
                }
            }
        }

        $partnership = $record['partnership'];

        if ($partnership === null) {
            return;
        }

        $status = $partnership->status?->value;

        if ($partnership->subtype === 'engaged') {
            $this->event('ENGA', $partnership->start, $partnership->place_id, always: true);
        } elseif ($partnership->subtype !== 'partner') {
            $this->line(1, 'MARR', $partnership->start->isEmpty() && $partnership->place_id === null ? 'Y' : '');

            if ($partnership->subtype !== 'marriage') {
                $this->line(2, 'TYPE', str_replace('_', ' ', $partnership->subtype));
            }

            $this->eventDetails($partnership->start, $partnership->place_id);
        }

        if ($status === 'divorced') {
            $this->event('DIV', $partnership->end, null, always: true);
        } elseif ($status === 'annulled') {
            $this->event('ANUL', $partnership->end, null, always: true);
        }

        // Exactly as we store it, for a faithful return trip.
        $this->line(1, '_SUBTYPE', $partnership->subtype);
        $this->optional(1, '_STATUS', $status);

        if ($partnership->subtype === 'partner' && ! $partnership->start->isEmpty()) {
            $this->line(1, 'EVEN');
            $this->line(2, 'TYPE', 'Partnership');
            $this->eventDetails($partnership->start, $partnership->place_id);
        }

        if (! $partnership->end->isEmpty() && ! in_array($status, ['divorced', 'annulled'], true)) {
            $this->line(1, '_END');
            $this->eventDetails($partnership->end, null);
        }

        if ($partnership->sequence !== null) {
            $this->line(1, '_SEQ', (string) $partnership->sequence);
        }

        $this->optional(1, 'NOTE', $partnership->notes);
    }

    private function event(string $tag, FuzzyDate $date, ?string $placeId, bool $always = false): void
    {
        $place = $placeId === null ? null : ($this->places[$placeId] ?? null);

        if ($date->isEmpty() && $place === null) {
            if ($always) {
                // "Y": it happened, nothing more is known.
                $this->line(1, $tag, 'Y');
            }

            return;
        }

        $this->line(1, $tag);
        $this->eventDetails($date, $placeId);
    }

    private function eventDetails(FuzzyDate $date, ?string $placeId): void
    {
        if (! $date->isEmpty()) {
            [$value, $phrase] = GedcomDate::write($date);
            $this->line(2, 'DATE', $value);
            $this->optional(3, 'PHRASE', $phrase);
        }

        if ($placeId !== null && isset($this->places[$placeId])) {
            $this->line(2, 'PLAC', $this->places[$placeId]);
        }
    }

    /**
     * Who is written as HUSB and who as WIFE. GEDCOM needs the two slots; they carry no
     * meaning for us beyond telling the partners apart.
     *
     * @param  array<string, string|null>  $genders
     * @return array{0: string, 1: string}
     */
    private function roles(string $a, string $b, array $genders): array
    {
        return ($genders[$a] ?? null) === 'female' && ($genders[$b] ?? null) !== 'female'
            || ($genders[$b] ?? null) === 'male' && ($genders[$a] ?? null) !== 'male'
                ? [$b, $a]
                : [$a, $b];
    }

    /** The NAME value: the full name, with the surname between slashes where it appears. */
    private static function nameValue(string $fullName, ?string $surname): string
    {
        $fullName = str_replace('/', ' ', $fullName);

        if ($surname === null || trim($surname) === '' || str_contains($surname, '/')) {
            return $fullName;
        }

        $at = mb_strripos($fullName, $surname);

        return $at === false
            ? $fullName
            : mb_substr($fullName, 0, $at).'/'.mb_substr($fullName, $at, mb_strlen($surname)).'/'.mb_substr($fullName, $at + mb_strlen($surname));
    }

    /**
     * @param  array<int, string>  $subtypes
     */
    private static function pedigree(array $subtypes): string
    {
        return match (true) {
            in_array('adoptive', $subtypes, true) => 'ADOPTED',
            in_array('foster', $subtypes, true) => 'FOSTER',
            in_array('biological', $subtypes, true) => 'BIRTH',
            default => 'OTHER',
        };
    }

    private function optional(int $level, string $tag, ?string $value): void
    {
        if ($value !== null && trim($value) !== '') {
            $this->line($level, $tag, $value);
        }
    }

    private function line(int $level, string $tag, string $value = '', ?string $xref = null): void
    {
        $parts = preg_split('/\r\n|\r|\n/', $value) ?: [''];

        foreach ($parts as $index => $part) {
            // A value may not begin with "@" unless it is a pointer: doubled, it is read as text.
            if (str_starts_with($part, '@') && preg_match('/^@[A-Z]\d+@$/', $part) !== 1) {
                $part = '@'.$part;
            }

            $this->lines[] = $index === 0
                ? rtrim($level.' '.($xref === null ? '' : $xref.' ').$tag.' '.$part)
                : rtrim(($level + 1).' CONT '.$part);
        }
    }
}
