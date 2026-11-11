<?php

use App\Services\Genealogy\FamilyGraph;
use App\Services\Genealogy\GenerationCalculator;

/**
 * Build a graph from short descriptions: "parent>child", "parent>child:adoptive", "a=b" (partners).
 *
 * @param  list<string>  $people
 * @param  list<string>  $links
 */
function graphOf(array $people, array $links): FamilyGraph
{
    $edges = [];

    foreach ($links as $i => $link) {
        if (str_contains($link, '=')) {
            [$a, $b] = explode('=', $link);
            $edges[] = ['id' => "e{$i}", 'type' => 'partnership', 'a' => min($a, $b), 'b' => max($a, $b), 'subtype' => 'marriage', 'status' => 'current', 'certainty' => 'confirmed', 'sequence' => null, 'start' => null];

            continue;
        }

        [$pair, $subtype] = array_pad(explode(':', $link), 2, 'biological');
        [$parent, $child] = explode('>', $pair);
        $edges[] = ['id' => "e{$i}", 'type' => 'parent_child', 'a' => $parent, 'b' => $child, 'subtype' => $subtype, 'status' => null, 'certainty' => 'confirmed', 'sequence' => null, 'start' => null];
    }

    return new FamilyGraph($people, $edges);
}

// Four generations: grandparents G1+G2, their children P1 (married S, who has no parents here)
// and P2; P1+S have C1 and C2; P1 also has H with another partner X (a half-sibling);
// C1 has a child K; A is adopted by P2; L is connected to nobody.
function sampleGraph(): FamilyGraph
{
    return graphOf(
        ['G1', 'G2', 'P1', 'P2', 'S', 'X', 'C1', 'C2', 'H', 'K', 'A', 'L'],
        ['G1=G2', 'G1>P1', 'G2>P1', 'G1>P2', 'G2>P2', 'P1=S', 'P1>C1', 'S>C1', 'P1>C2', 'S>C2', 'P1=X', 'P1>H', 'X>H', 'C1>K', 'P2>A:adoptive'],
    );
}

test('parents, children and partners', function () {
    $graph = sampleGraph();

    expect($graph->parentIds('C1'))->toEqualCanonicalizing(['P1', 'S'])
        ->and($graph->childIds('P1'))->toEqualCanonicalizing(['C1', 'C2', 'H'])
        ->and($graph->partnerIds('P1'))->toEqualCanonicalizing(['S', 'X'])
        ->and($graph->arePartners('S', 'P1'))->toBeTrue()
        ->and($graph->arePartners('S', 'X'))->toBeFalse();
});

test('siblings are derived from shared parents, half-siblings included', function () {
    $graph = sampleGraph();

    expect($graph->siblingIds('C1'))->toEqualCanonicalizing(['C2', 'H'])
        ->and($graph->siblingIds('H'))->toEqualCanonicalizing(['C1', 'C2'])
        ->and($graph->siblingIds('K'))->toBe([]);
});

test('biological parents exclude adoptive ones', function () {
    $graph = sampleGraph();

    expect($graph->parentIds('A'))->toBe(['P2'])
        ->and($graph->biologicalParentIds('A'))->toBe([]);
});

test('ancestors and descendants with distances', function () {
    $graph = sampleGraph();

    expect($graph->ancestors('K'))->toEqual(['C1' => 1, 'P1' => 2, 'S' => 2, 'G1' => 3, 'G2' => 3])
        ->and($graph->descendants('G1'))->toHaveKeys(['P1', 'P2', 'C1', 'C2', 'H', 'K', 'A'])
        ->and($graph->descendants('G1')['K'])->toBe(3)
        ->and($graph->isAncestorOf('G2', 'K'))->toBeTrue()
        ->and($graph->isAncestorOf('K', 'G2'))->toBeFalse();
});

test('edges to people who are not in the tree are ignored', function () {
    $graph = graphOf(['A'], ['A>B', 'A=C']);

    expect($graph->childIds('A'))->toBe([])->and($graph->partnerIds('A'))->toBe([]);
});

test('generations: ancestors are 1, children one more, spouses follow their partner', function () {
    $generations = (new GenerationCalculator)->calculate(sampleGraph());

    expect($generations)->toMatchArray([
        'G1' => 1, 'G2' => 1,
        'P1' => 2, 'P2' => 2,
        'S' => 2, 'X' => 2,          // no parents in the tree: they take their partner's generation
        'C1' => 3, 'C2' => 3, 'H' => 3, 'A' => 3,
        'K' => 4,
        'L' => null,                 // connected to nobody
    ]);
});

test('generations: the deeper parent wins', function () {
    // T is generation 1; M is T's grandchild (3). W (T's child, 2) and M have a child Z.
    $generations = (new GenerationCalculator)->calculate(
        graphOf(['T', 'W', 'V', 'M', 'Z'], ['T>W', 'T>V', 'V>M', 'W>Z', 'M>Z']),
    );

    expect($generations['W'])->toBe(2)->and($generations['M'])->toBe(3)->and($generations['Z'])->toBe(4);
});

test('generations: a married-in spouse pulls their own children down too', function () {
    // S has no parents and marries P (generation 3). S's child from elsewhere is then 4.
    $generations = (new GenerationCalculator)->calculate(
        graphOf(['R', 'Q', 'P', 'S', 'E'], ['R>Q', 'Q>P', 'P=S', 'S>E']),
    );

    expect($generations['S'])->toBe(3)->and($generations['E'])->toBe(4);
});

test('generations: a cycle that slipped through cannot hang the calculator', function () {
    $generations = (new GenerationCalculator)->calculate(graphOf(['A', 'B'], ['A>B', 'B>A']));

    expect($generations)->toHaveKeys(['A', 'B']);
});
