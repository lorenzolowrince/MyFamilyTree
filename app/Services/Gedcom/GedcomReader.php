<?php

namespace App\Services\Gedcom;

use App\Enums\Certainty;
use App\Enums\ParentSubtype;
use App\Enums\PartnershipStatus;
use App\Enums\PartnershipSubtype;

/**
 * Turns a parsed GEDCOM file into the people and links it describes, in the shapes our own
 * actions take (CreateMember, LinkPartners, LinkParentAndChild). Nothing is saved here.
 *
 * Reads what GedcomWriter writes without loss, and the standard parts of files from other
 * programs. Anything it does not understand is ignored and counted, never guessed.
 *
 * @phpstan-type Person array{xref: string, name: string, birth_year: int|null, death_year: int|null, gender: string|null, placeholder: bool, data: array<string, mixed>, names: list<array{type: string, name: string, notes: string|null}>}
 * @phpstan-type Document array{people: array<string, Person>, partnerships: list<array{a: string, b: string, details: array<string, mixed>, sequence: int|null}>, parents: list<array{parent: string, child: string, subtype: string, certainty: string}>, warnings: list<string>, version: string|null, source: string|null}
 */
class GedcomReader
{
    private const SEX = ['M' => 'male', 'F' => 'female', 'X' => 'other', 'U' => 'unknown'];

    private const NAME_TYPES = ['BIRTH' => 'birth', 'MARRIED' => 'married', 'AKA' => 'alias', 'IMMIGRANT' => 'other', 'PROFESSIONAL' => 'alias', 'OTHER' => 'other'];

    private const PEDIGREE = ['BIRTH' => 'biological', 'NATURAL' => 'biological', 'BIOLOGICAL' => 'biological', 'ADOPTED' => 'adoptive', 'ADOPTIVE' => 'adoptive', 'FOSTER' => 'foster', 'STEP' => 'step', 'GUARDIAN' => 'guardian', 'UNKNOWN' => 'unknown'];

    public function __construct(private readonly GedcomParser $parser) {}

    /**
     * @return Document
     */
    public function read(string $text): array
    {
        $parsed = $this->parser->parse($text);
        $warnings = $parsed['warnings'];

        $people = [];
        $families = [];
        $ignored = [];
        $version = $source = null;

        foreach ($parsed['records'] as $record) {
            match (true) {
                $record['tag'] === 'INDI' && $record['xref'] !== null => $people[$record['xref']] = $this->person($record),
                $record['tag'] === 'FAM' && $record['xref'] !== null => $families[$record['xref']] = $record,
                $record['tag'] === 'HEAD' => [$version, $source] = [
                    GedcomParser::value(GedcomParser::child($record, 'GEDC') ?? ['children' => []], 'VERS'),
                    GedcomParser::value($record, 'SOUR'),
                ],
                in_array($record['tag'], ['TRLR', 'SUBM', 'SUBN'], true) => null,
                default => $ignored[$record['tag']] = ($ignored[$record['tag']] ?? 0) + 1,
            };
        }

        foreach ($ignored as $tag => $count) {
            GedcomParser::warn($warnings, match ($tag) {
                'SOUR', 'REPO' => __(':count source record(s) were not imported: sources are not supported yet.', ['count' => $count]),
                'OBJE' => __(':count media record(s) were not imported: upload photos and documents separately.', ['count' => $count]),
                'NOTE', 'SNOTE' => __(':count shared note(s) were not imported.', ['count' => $count]),
                default => __(':count ":tag" record(s) were not imported.', ['count' => $count, 'tag' => $tag]),
            });
        }

        $partnerships = [];
        $parents = [];
        $seen = [];

        foreach ($families as $xref => $family) {
            $husband = $this->pointer($family, 'HUSB', $people);
            $wife = $this->pointer($family, 'WIFE', $people);

            if ($husband !== null && $wife !== null && $husband !== $wife) {
                $key = strcmp($husband, $wife) < 0 ? "{$husband}|{$wife}" : "{$wife}|{$husband}";

                if (isset($seen[$key])) {
                    GedcomParser::warn($warnings, __(':a and :b are recorded as a couple more than once; the first record is used.', ['a' => $people[$husband]['name'], 'b' => $people[$wife]['name']]));
                } else {
                    $seen[$key] = true;
                    $sequence = GedcomParser::value($family, '_SEQ');
                    $partnerships[] = ['a' => $husband, 'b' => $wife, 'details' => $this->partnership($family), 'sequence' => is_numeric($sequence) ? (int) $sequence : null];
                }
            }

            foreach (GedcomParser::children($family, 'CHIL') as $entry) {
                $child = trim($entry['value']);

                if (! isset($people[$child])) {
                    GedcomParser::warn($warnings, __('Family :xref lists a child who is not in the file.', ['xref' => $xref]));

                    continue;
                }

                $fallback = $this->pedigreeOf($parsed['records'], $child, $xref);

                foreach ([[$husband, '_FREL', '_FCERT'], [$wife, '_MREL', '_MCERT']] as [$parent, $relTag, $certaintyTag]) {
                    if ($parent === null || $parent === $child) {
                        continue;
                    }

                    $subtype = self::PEDIGREE[strtoupper((string) GedcomParser::value($entry, $relTag))] ?? $fallback;
                    $certainty = Certainty::tryFrom(strtolower((string) GedcomParser::value($entry, $certaintyTag))) ?? Certainty::Confirmed;

                    $parents[] = [
                        'parent' => $parent,
                        'child' => $child,
                        'subtype' => (ParentSubtype::tryFrom($subtype) ?? ParentSubtype::Unknown)->value,
                        'certainty' => $certainty->value,
                    ];
                }
            }
        }

        // The order of each person's marriages, where the file gives it.
        usort($partnerships, fn (array $x, array $y): int => ($x['sequence'] ?? PHP_INT_MAX) <=> ($y['sequence'] ?? PHP_INT_MAX));

        if ($people === []) {
            GedcomParser::warn($warnings, __('No people were found in the file.'));
        }

        return compact('people', 'partnerships', 'parents', 'warnings', 'version', 'source');
    }

    /**
     * @param  array<string, mixed>  $record
     * @return Person
     */
    private function person(array $record): array
    {
        $names = GedcomParser::children($record, 'NAME');
        $data = ['full_name' => __('Unknown')];
        $others = [];
        $primary = false;

        foreach ($names as $name) {
            $value = self::clean(str_replace('/', ' ', $name['value']));
            $type = strtoupper((string) GedcomParser::value($name, 'TYPE'));

            if ($value === '') {
                continue;
            }

            if ($type === 'MAIDEN' && ! isset($data['maiden_name'])) {
                $data['maiden_name'] = $value;

                continue;
            }

            // The first name without a type (or, failing that, the first name) is the person's name.
            if (! $primary && ($type === '' || $name === $names[0])) {
                $primary = true;
                $data['full_name'] = $value;
                $surname = preg_match('~/([^/]*)/~u', $name['value'], $match) === 1 ? self::clean($match[1]) : null;
                $data['surname'] = GedcomParser::value($name, 'SURN') ?? ($surname === '' ? null : $surname);
                $data['given_names'] = GedcomParser::value($name, 'GIVN');
                $data['nickname'] = GedcomParser::value($name, 'NICK');

                continue;
            }

            $phrase = strtolower((string) GedcomParser::value(GedcomParser::child($name, 'TYPE') ?? ['children' => []], 'PHRASE'));

            $others[] = [
                'type' => in_array($phrase, ['religious', 'romanised'], true) ? $phrase : (self::NAME_TYPES[$type] ?? 'other'),
                'name' => $value,
                'notes' => GedcomParser::value($name, 'NOTE'),
            ];
        }

        $gender = self::SEX[strtoupper((string) GedcomParser::value($record, 'SEX'))] ?? null;

        if ($gender !== null) {
            $data['gender'] = $gender;
        }

        $birth = GedcomParser::child($record, 'BIRT');
        $death = GedcomParser::child($record, 'DEAT');

        if ($birth !== null) {
            $data['birth'] = self::date($birth);
            $data['birth_place'] = GedcomParser::value($birth, 'PLAC');
        }

        if ($death !== null) {
            $data['living_status'] = 'deceased';
            $data['death'] = self::date($death);
            $data['death_place'] = GedcomParser::value($death, 'PLAC');
        } elseif (GedcomParser::child($record, 'BURI') !== null || GedcomParser::child($record, 'CREM') !== null) {
            $data['living_status'] = 'deceased';
        } elseif (strtoupper((string) GedcomParser::value($record, '_LIVING')) === 'Y') {
            $data['living_status'] = 'living';
        }

        $residence = GedcomParser::child($record, 'RESI');

        $data = [
            ...$data,
            'hometown' => $residence === null ? null : GedcomParser::value($residence, 'PLAC'),
            'occupation' => self::limit(GedcomParser::value($record, 'OCCU'), 200),
            'education' => self::limit(GedcomParser::value($record, 'EDUC'), 200),
            'nationality' => self::limit(GedcomParser::value($record, 'NATI'), 100),
            'biography' => self::limit(implode("\n\n", array_filter(array_map(fn (array $note): string => trim($note['value']), GedcomParser::children($record, 'NOTE')))) ?: null, 20000),
            'notes' => self::limit(GedcomParser::value($record, '_RESEARCH'), 10000),
            'visibility' => match (strtoupper((string) GedcomParser::value($record, 'RESN'))) {
                'PRIVACY' => 'private',
                'CONFIDENTIAL' => 'restricted',
                default => 'family',
            },
        ];

        $data['full_name'] = (string) self::limit($data['full_name'], 200);

        return [
            'xref' => (string) $record['xref'],
            'name' => $data['full_name'],
            'birth_year' => $data['birth']['year'] ?? null,
            'death_year' => $data['death']['year'] ?? null,
            'gender' => $gender,
            'placeholder' => strtoupper((string) GedcomParser::value($record, '_PLACEHOLDER')) === 'Y',
            'data' => array_filter($data, fn (mixed $value): bool => $value !== null),
            'names' => $others,
        ];
    }

    /**
     * @param  array<string, mixed>  $family
     * @return array<string, mixed>
     */
    private function partnership(array $family): array
    {
        $marriage = GedcomParser::child($family, 'MARR');
        $engagement = GedcomParser::child($family, 'ENGA');
        $divorce = GedcomParser::child($family, 'DIV');
        $annulment = GedcomParser::child($family, 'ANUL');
        $ended = GedcomParser::child($family, '_END');

        $partnered = null;

        foreach (GedcomParser::children($family, 'EVEN') as $event) {
            if (strtolower((string) GedcomParser::value($event, 'TYPE')) === 'partnership') {
                $partnered = $event;
            }
        }

        $type = strtolower((string) ($marriage === null ? '' : GedcomParser::value($marriage, 'TYPE')));

        $subtype = PartnershipSubtype::tryFrom(strtolower((string) GedcomParser::value($family, '_SUBTYPE')))->value ?? match (true) {
            $marriage !== null && str_contains($type, 'custom') => 'customary_marriage',
            $marriage !== null && str_contains($type, 'civil') => 'civil_union',
            $marriage !== null => 'marriage',
            $engagement !== null => 'engaged',
            // Other programs often list a couple with no marriage event at all.
            default => 'partner',
        };

        $status = PartnershipStatus::tryFrom(strtolower((string) GedcomParser::value($family, '_STATUS')))->value ?? match (true) {
            $divorce !== null => 'divorced',
            $annulment !== null => 'annulled',
            default => 'current',
        };

        $start = $marriage ?? $engagement ?? $partnered;
        $end = $divorce ?? $annulment ?? $ended;

        return [
            'subtype' => $subtype,
            'status' => $status,
            'start' => $start === null ? null : self::date($start),
            'end' => $end === null ? null : self::date($end),
            'place' => $start === null ? null : GedcomParser::value($start, 'PLAC'),
            'notes' => self::limit(GedcomParser::value($family, 'NOTE'), 5000),
        ];
    }

    /**
     * How a child is related to a family, from the child's own record (`FAMC` → `PEDI`).
     *
     * @param  list<array<string, mixed>>  $records
     */
    private function pedigreeOf(array $records, string $child, string $family): string
    {
        foreach ($records as $record) {
            if ($record['xref'] !== $child) {
                continue;
            }

            foreach (GedcomParser::children($record, 'FAMC') as $link) {
                if (trim($link['value']) === $family) {
                    return self::PEDIGREE[strtoupper((string) GedcomParser::value($link, 'PEDI'))] ?? 'biological';
                }
            }
        }

        return 'biological';
    }

    /**
     * @param  array<string, mixed>  $family
     * @param  array<string, mixed>  $people
     */
    private function pointer(array $family, string $tag, array $people): ?string
    {
        $value = GedcomParser::value($family, $tag);

        return $value !== null && isset($people[$value]) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private static function date(array $event): array
    {
        $date = GedcomParser::child($event, 'DATE');

        return GedcomDate::read($date['value'] ?? null, $date === null ? null : GedcomParser::value($date, 'PHRASE'));
    }

    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private static function limit(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
