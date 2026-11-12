<?php

namespace App\Services\Genealogy;

/**
 * Words for a relationship worked out by the RelationshipCalculator: "the second person is
 * the first person's ...". All wording lives here (through the translator), so another
 * language or local kinship terms need no change to the calculator.
 *
 * Gendered words are used when the gender is recorded, neutral ones otherwise.
 */
class RelationshipLabeler
{
    private const ORDINALS = [1 => 'first', 'second', 'third', 'fourth', 'fifth', 'sixth', 'seventh', 'eighth', 'ninth', 'tenth'];

    private const TIMES = [1 => 'once', 'twice', 'three times', 'four times', 'five times', 'six times', 'seven times', 'eight times', 'nine times', 'ten times'];

    /**
     * @param  array<string, mixed>  $relation  one answer from RelationshipCalculator::between()
     * @param  array<string, string|null>  $genders  person id => 'male', 'female' or anything else
     */
    public function label(array $relation, array $genders): string
    {
        /** @var list<array{id: string, via: string|null, subtype: string|null}> $path */
        $path = $relation['path'];
        $last = $path === [] ? null : $path[count($path) - 1]['id'];
        $gender = $last === null ? null : ($genders[$last] ?? null);

        return match ($relation['kind']) {
            'self' => __('the same person'),
            'unrelated' => __('not connected in this tree'),
            'connected' => __('connected through other relatives'),
            'partner' => $this->partnerWord($relation['subtype'], $gender, (bool) $relation['former']),
            'in_law' => $this->inLaw($relation, $genders, $gender),
            default => $this->descent($relation, $gender),
        };
    }

    /**
     * @param  array<string, mixed>  $relation
     */
    private function descent(array $relation, ?string $gender): string
    {
        $up = (int) $relation['up'];
        $down = (int) $relation['down'];

        $word = match ($relation['kind']) {
            'ancestor' => self::generations($up, self::pick($gender, 'father', 'mother', 'parent')),
            'descendant' => self::generations($down, self::pick($gender, 'son', 'daughter', 'child')),
            'sibling' => self::pick($gender, 'brother', 'sister', 'sibling'),
            'uncle_aunt' => self::generations($up - 1, self::pick($gender, 'uncle', 'aunt', 'uncle or aunt'), 'grand-'),
            'niece_nephew' => self::generations($down - 1, self::pick($gender, 'nephew', 'niece', 'niece or nephew'), 'grand-'),
            default => self::cousin((int) $relation['degree'], (int) $relation['removed']),
        };

        if ($relation['half']) {
            $word = $relation['kind'] === 'cousin' ? "half {$word}" : "half-{$word}";
        }

        $word = match ($relation['qualifier']) {
            // "stepmother", "stepson", "stepbrother"; further out, "step-grandfather".
            'step' => ($relation['kind'] === 'sibling' || $up + $down === 1) && ! $relation['half'] ? "step{$word}" : "step-{$word}",
            'adoptive' => "adoptive {$word}",
            'foster' => "foster {$word}",
            'guardian' => $relation['kind'] === 'ancestor' && $up === 1 ? 'guardian' : ($relation['kind'] === 'descendant' && $down === 1 ? 'ward' : "{$word} by guardianship"),
            default => $word,
        };

        return __($word);
    }

    /**
     * @param  array<string, mixed>  $relation
     * @param  array<string, string|null>  $genders
     */
    private function inLaw(array $relation, array $genders, ?string $gender): string
    {
        /** @var array<string, mixed> $base */
        $base = $relation['base'];
        /** @var list<array{id: string, via: string|null, subtype: string|null}> $path */
        $path = $relation['path'];
        $kind = $base['kind'];
        $close = $base['qualifier'] === null && ! $base['half'];
        $former = $relation['former'] ? 'former ' : '';

        if ($relation['form'] === 'relative_of_partner') {
            // $path[1] is the first person's partner; the second person is that partner's relative.
            $partner = $this->partnerWord($path[1]['subtype'], $genders[$path[1]['id']] ?? null, (bool) $relation['former']);

            return __(match (true) {
                $close && $kind === 'ancestor' && $base['up'] === 1 => $former.self::pick($gender, 'father-in-law', 'mother-in-law', 'parent-in-law'),
                $close && $kind === 'sibling' => $former.self::pick($gender, 'brother-in-law', 'sister-in-law', 'sibling-in-law'),
                $close && $kind === 'descendant' && $base['down'] === 1 => $former.self::pick($gender, 'stepson', 'stepdaughter', 'stepchild'),
                default => "{$partner}'s ".$this->descent($base, $gender),
            });
        }

        $spouse = $this->partnerWord($path[count($path) - 1]['subtype'], $gender, (bool) $relation['former']);
        $relative = $path[count($path) - 2]['id'];
        $relativeWord = $this->descent($base, $genders[$relative] ?? null);

        if ($relation['form'] === 'partner_of_relative') {
            return __(match (true) {
                $close && $kind === 'descendant' && $base['down'] === 1 => $former.self::pick($gender, 'son-in-law', 'daughter-in-law', 'child-in-law'),
                $close && $kind === 'sibling' => $former.self::pick($gender, 'brother-in-law', 'sister-in-law', 'sibling-in-law'),
                $close && $kind === 'ancestor' && $base['up'] === 1 => $former.self::pick($gender, 'stepfather', 'stepmother', 'step-parent'),
                $close && $kind === 'uncle_aunt' && $base['up'] === 2 && ! $relation['former'] => self::pick($gender, 'uncle', 'aunt', 'uncle or aunt').' by marriage',
                default => "{$relativeWord}'s {$spouse}",
            });
        }

        // The partner of a relative of the first person's partner.
        $partner = $this->partnerWord($path[1]['subtype'], $genders[$path[1]['id']] ?? null, false);

        return __($close && $kind === 'sibling' && ! $relation['former']
            ? self::pick($gender, 'brother-in-law', 'sister-in-law', 'sibling-in-law')
            : "{$partner}'s {$relativeWord}'s {$spouse}");
    }

    private function partnerWord(?string $subtype, ?string $gender, bool $former): string
    {
        $word = match ($subtype) {
            'marriage', 'customary_marriage' => self::pick($gender, 'husband', 'wife', 'spouse'),
            'engaged' => self::pick($gender, 'fiancé', 'fiancée', 'betrothed'),
            default => 'partner',
        };

        return __($former ? "former {$word}" : $word);
    }

    /** "father", "grandfather", "great-grandfather", "great-great-grandfather"... */
    private static function generations(int $steps, string $word, string $grand = 'grand'): string
    {
        return match (true) {
            $steps <= 1 => $word,
            $steps === 2 => $grand.$word,
            default => str_repeat('great-', $steps - 2).$grand.$word,
        };
    }

    private static function cousin(int $degree, int $removed): string
    {
        $word = (self::ORDINALS[$degree] ?? "{$degree}th").' cousin';

        return $removed === 0 ? $word : $word.' '.(self::TIMES[$removed] ?? "{$removed} times").' removed';
    }

    private static function pick(?string $gender, string $male, string $female, string $neutral): string
    {
        return match ($gender) {
            'male' => $male,
            'female' => $female,
            default => $neutral,
        };
    }
}
