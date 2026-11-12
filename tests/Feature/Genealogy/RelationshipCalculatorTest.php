<?php

use App\Services\Genealogy\FamilyGraph;
use App\Services\Genealogy\RelationshipCalculator;
use App\Services\Genealogy\RelationshipLabeler;

/*
| The relationship calculator against the fixture family of the architecture documents
| (four generations, two marriages, half-siblings, an adoption, a step-parent, a cousin
| marriage), plus in-laws. No database: the calculator only sees a FamilyGraph.
|
|   Gen 1  Lidau ═ Rumind
|   Gen 2  Gimbang ═(1) Sarah (divorced) ═(2) Linah          Jaineh ═ Paulus
|   Gen 3  Daniel, Maria (with Sarah) · Josie (with Linah) · Anna (adopted)      Rita
|   Gen 4  Daniel ═ Rita (cousins) → Adam, Eve · Maria → Noah · Josie → Uma
|   Gen 5  Adam → Baby
|   By marriage: Eve ═ Tom; Tom's parents Pa ═ Ma; Tom's sister Tina ═ Ken. Zed: nobody's relative.
*/

const KIN_GENDERS = [
    'Lidau' => 'male', 'Rumind' => 'female', 'Gimbang' => 'male', 'Sarah' => 'female', 'Linah' => 'female',
    'Jaineh' => 'female', 'Paulus' => 'male', 'Daniel' => 'male', 'Maria' => 'female', 'Josie' => 'female',
    'Anna' => 'female', 'Rita' => 'female', 'Adam' => 'male', 'Eve' => 'female', 'Noah' => 'male',
    'Uma' => null, 'Baby' => 'male', 'Tom' => 'male', 'Pa' => 'male', 'Ma' => 'female', 'Tina' => 'female',
    'Ken' => 'male', 'Zed' => 'male',
];

/**
 * "parent>child", "parent>child:adoptive", "parent>child:biological:possible", "a=b", "a=b:divorced".
 *
 * @param  list<string>  $people
 * @param  list<string>  $links
 */
function kinGraph(array $people, array $links): FamilyGraph
{
    $edges = [];

    foreach ($links as $i => $link) {
        $parts = explode(':', $link);

        if (str_contains($parts[0], '=')) {
            [$a, $b] = explode('=', $parts[0]);
            $edges[] = ['id' => "e{$i}", 'type' => 'partnership', 'a' => $a, 'b' => $b, 'subtype' => 'marriage', 'status' => $parts[1] ?? 'current', 'certainty' => 'confirmed', 'sequence' => null, 'start' => null];

            continue;
        }

        [$parent, $child] = explode('>', $parts[0]);
        $edges[] = ['id' => "e{$i}", 'type' => 'parent_child', 'a' => $parent, 'b' => $child, 'subtype' => $parts[1] ?? 'biological', 'status' => null, 'certainty' => $parts[2] ?? 'confirmed', 'sequence' => null, 'start' => null];
    }

    return new FamilyGraph($people, $edges);
}

function fixtureFamily(): FamilyGraph
{
    return kinGraph(array_keys(KIN_GENDERS), [
        'Lidau=Rumind', 'Lidau>Gimbang', 'Rumind>Gimbang', 'Lidau>Jaineh', 'Rumind>Jaineh',
        'Gimbang=Sarah:divorced', 'Gimbang=Linah',
        'Gimbang>Daniel', 'Sarah>Daniel', 'Gimbang>Maria', 'Sarah>Maria',
        'Gimbang>Josie', 'Linah>Josie',
        'Gimbang>Anna:adoptive', 'Linah>Anna:adoptive',
        'Linah>Daniel:step',
        'Jaineh=Paulus', 'Jaineh>Rita', 'Paulus>Rita',
        'Daniel=Rita', 'Daniel>Adam', 'Rita>Adam', 'Daniel>Eve', 'Rita>Eve',
        'Maria>Noah', 'Josie>Uma', 'Adam>Baby',
        'Eve=Tom', 'Pa=Ma', 'Pa>Tom', 'Ma>Tom', 'Pa>Tina', 'Ma>Tina', 'Tina=Ken',
    ]);
}

/**
 * "$y is $x's ...": every answer, closest first.
 *
 * @return list<string>
 */
function kinship(string $x, string $y, ?FamilyGraph $graph = null): array
{
    $labeler = new RelationshipLabeler;

    return array_map(
        fn (array $relation): string => $labeler->label($relation, KIN_GENDERS),
        (new RelationshipCalculator)->between($graph ?? fixtureFamily(), $x, $y),
    );
}

test('direct line: parents, children and further generations', function () {
    expect(kinship('Noah', 'Maria'))->toBe(['mother'])
        ->and(kinship('Maria', 'Noah'))->toBe(['son'])
        ->and(kinship('Rita', 'Paulus'))->toBe(['father'])
        ->and(kinship('Noah', 'Gimbang'))->toBe(['grandfather'])
        ->and(kinship('Sarah', 'Eve'))->toBe(['granddaughter'])
        ->and(kinship('Noah', 'Lidau'))->toBe(['great-grandfather'])
        // Reached through both parents (cousin marriage): still one answer.
        ->and(kinship('Adam', 'Lidau'))->toBe(['great-grandfather'])
        ->and(kinship('Baby', 'Rumind'))->toBe(['great-great-grandmother'])
        ->and(kinship('Lidau', 'Baby'))->toBe(['great-great-grandson'])
        // Gender not recorded: neutral words.
        ->and(kinship('Josie', 'Uma'))->toBe(['child'])
        ->and(kinship('Linah', 'Uma'))->toBe(['grandchild']);
});

test('siblings: full, half and adoptive', function () {
    expect(kinship('Daniel', 'Maria'))->toBe(['sister'])
        ->and(kinship('Maria', 'Daniel'))->toBe(['brother'])
        ->and(kinship('Daniel', 'Josie'))->toBe(['half-sister'])
        ->and(kinship('Josie', 'Maria'))->toBe(['half-sister'])
        ->and(kinship('Josie', 'Anna'))->toBe(['adoptive sister'])
        ->and(kinship('Daniel', 'Anna'))->toBe(['adoptive half-sister']);

    // One parent recorded for each: nothing says they are only half-siblings.
    $graph = kinGraph(['Gimbang', 'Daniel', 'Maria'], ['Gimbang>Daniel', 'Gimbang>Maria']);
    expect(kinship('Daniel', 'Maria', $graph))->toBe(['sister']);
});

test('step relationships are used only when there is no relationship by descent', function () {
    expect(kinship('Daniel', 'Linah'))->toBe(['stepmother'])
        ->and(kinship('Linah', 'Daniel'))->toBe(['stepson'])
        ->and(kinship('Linah', 'Adam'))->toBe(['step-grandson'])
        // No step link recorded for Maria: found through her father's marriage instead.
        ->and(kinship('Maria', 'Linah'))->toBe(['stepmother'])
        ->and(kinship('Linah', 'Maria'))->toBe(['stepdaughter'])
        // The step link does not turn half-siblings into full ones.
        ->and(kinship('Josie', 'Daniel'))->toBe(['half-brother']);
});

test('uncles, aunts, nieces and nephews', function () {
    expect(kinship('Daniel', 'Jaineh'))->toBe(['aunt'])
        ->and(kinship('Jaineh', 'Daniel'))->toBe(['nephew'])
        ->and(kinship('Noah', 'Daniel'))->toBe(['uncle'])
        ->and(kinship('Uma', 'Daniel'))->toBe(['half-uncle'])
        ->and(kinship('Uma', 'Anna'))->toBe(['adoptive aunt'])
        ->and(kinship('Noah', 'Jaineh'))->toBe(['grand-aunt'])
        ->and(kinship('Jaineh', 'Noah'))->toBe(['grand-nephew'])
        ->and(kinship('Baby', 'Maria')[0])->toBe('grand-aunt')
        ->and(kinship('Jaineh', 'Uma'))->toBe(['grand-niece or nephew']);
});

test('cousins, with degree and removal', function () {
    expect(kinship('Maria', 'Rita'))->toBe(['first cousin'])
        ->and(kinship('Josie', 'Rita'))->toBe(['first cousin'])
        ->and(kinship('Noah', 'Rita'))->toBe(['first cousin once removed'])
        ->and(kinship('Rita', 'Noah'))->toBe(['first cousin once removed'])
        ->and(kinship('Noah', 'Uma'))->toBe(['half first cousin'])
        ->and(kinship('Anna', 'Rita'))->toBe(['adoptive first cousin']);
});

test('a cousin marriage gives more than one true answer, the closest first', function () {
    expect(kinship('Daniel', 'Rita'))->toBe(['wife', 'first cousin'])
        ->and(kinship('Rita', 'Daniel'))->toBe(['husband', 'first cousin'])
        // Their children are siblings, and second cousins through the two grandparents' lines.
        ->and(kinship('Adam', 'Eve'))->toBe(['sister', 'second cousin'])
        // His father is also his mother's cousin.
        ->and(kinship('Adam', 'Daniel'))->toBe(['father', 'first cousin once removed'])
        ->and(kinship('Daniel', 'Adam'))->toBe(['son', 'first cousin once removed'])
        ->and(kinship('Adam', 'Gimbang'))->toBe(['grandfather', 'grand-uncle'])
        ->and(kinship('Adam', 'Noah'))->toBe(['first cousin', 'second cousin'])
        ->and(kinship('Adam', 'Maria'))->toBe(['aunt', 'first cousin once removed'])
        ->and(kinship('Adam', 'Josie'))->toBe(['half-aunt', 'first cousin once removed'])
        ->and(kinship('Adam', 'Jaineh'))->toBe(['grandmother', 'grand-aunt'])
        ->and(kinship('Rita', 'Gimbang'))->toBe(['uncle']);
});

test('partners, former partners and relatives by marriage', function () {
    expect(kinship('Gimbang', 'Linah'))->toBe(['wife'])
        ->and(kinship('Sarah', 'Gimbang'))->toBe(['former husband'])
        ->and(kinship('Tom', 'Daniel'))->toBe(['father-in-law'])
        ->and(kinship('Tom', 'Rita'))->toBe(['mother-in-law'])
        ->and(kinship('Daniel', 'Tom'))->toBe(['son-in-law'])
        ->and(kinship('Tom', 'Adam'))->toBe(['brother-in-law'])
        ->and(kinship('Adam', 'Tom'))->toBe(['brother-in-law'])
        ->and(kinship('Eve', 'Tina'))->toBe(['sister-in-law'])
        ->and(kinship('Eve', 'Pa'))->toBe(['father-in-law'])
        // Husband's sister's husband.
        ->and(kinship('Eve', 'Ken'))->toBe(['brother-in-law'])
        ->and(kinship('Tom', 'Gimbang'))->toBe(['wife\'s grandfather'])
        ->and(kinship('Gimbang', 'Tom'))->toBe(['granddaughter\'s husband'])
        ->and(kinship('Maria', 'Paulus'))->toBe(['uncle by marriage'])
        // The closest of several routes: his daughter's husband, not only his wife's nephew.
        ->and(kinship('Paulus', 'Daniel'))->toBe(['son-in-law'])
        ->and(kinship('Paulus', 'Maria'))->toBe(['wife\'s niece'])
        ->and(kinship('Sarah', 'Josie'))->toBe(['former stepdaughter']);
});

test('connected only through a chain of others, or not at all', function () {
    expect(kinship('Sarah', 'Linah'))->toBe(['connected through other relatives'])
        ->and(kinship('Adam', 'Tina'))->toBe(['connected through other relatives'])
        ->and(kinship('Adam', 'Zed'))->toBe(['not connected in this tree'])
        ->and(kinship('Adam', 'Adam'))->toBe(['the same person'])
        ->and(kinship('Adam', 'Nobody'))->toBe(['not connected in this tree']);

    $chain = (new RelationshipCalculator)->between(fixtureFamily(), 'Sarah', 'Linah')[0];

    // Through the husband they both had, or through Sarah's son whose stepmother Linah is.
    expect($chain['kind'])->toBe('connected')
        ->and($chain['path'])->toHaveCount(3)
        ->and($chain['path'][0])->toBe(['id' => 'Sarah', 'via' => null, 'subtype' => null])
        ->and($chain['path'][1]['id'])->toBeIn(['Gimbang', 'Daniel'])
        ->and($chain['path'][2]['id'])->toBe('Linah');
});

test('the answer carries the path of people, for drawing', function () {
    $calculator = new RelationshipCalculator;

    $cousins = $calculator->between(fixtureFamily(), 'Adam', 'Noah')[0];

    expect($cousins)->toMatchArray(['kind' => 'cousin', 'up' => 2, 'down' => 2, 'degree' => 1, 'removed' => 0, 'half' => false, 'qualifier' => null])
        ->and($cousins['apexes'])->toEqualCanonicalizing(['Gimbang', 'Sarah'])
        ->and(array_column($cousins['path'], 'via'))->toBe([null, 'parent', 'parent', 'child', 'child'])
        ->and($cousins['path'][0]['id'])->toBe('Adam')
        ->and($cousins['path'][1]['id'])->toBe('Daniel')
        ->and($cousins['path'][2]['id'])->toBeIn(['Gimbang', 'Sarah'])
        ->and($cousins['path'][3]['id'])->toBe('Maria')
        ->and($cousins['path'][4]['id'])->toBe('Noah');

    $inLaw = $calculator->between(fixtureFamily(), 'Tom', 'Daniel')[0];

    expect($inLaw['kind'])->toBe('in_law')
        ->and(array_column($inLaw['path'], 'id'))->toBe(['Tom', 'Eve', 'Daniel'])
        ->and(array_column($inLaw['path'], 'via'))->toBe([null, 'partner', 'parent']);

    $adoptive = $calculator->between(fixtureFamily(), 'Anna', 'Linah')[0];

    expect($adoptive['qualifier'])->toBe('adoptive')
        ->and($adoptive['path'][1])->toBe(['id' => 'Linah', 'via' => 'parent', 'subtype' => 'adoptive'])
        ->and(kinship('Anna', 'Linah'))->toBe(['adoptive mother'])
        ->and(kinship('Linah', 'Anna'))->toBe(['adoptive daughter']);
});

test('a path over a possible or disputed link is flagged as uncertain', function () {
    $graph = kinGraph(['Lidau', 'Gimbang', 'Daniel'], ['Lidau>Gimbang:biological:possible', 'Gimbang>Daniel']);
    $calculator = new RelationshipCalculator;

    expect($calculator->between($graph, 'Daniel', 'Lidau')[0]['uncertain'])->toBeTrue()
        ->and($calculator->between($graph, 'Daniel', 'Gimbang')[0]['uncertain'])->toBeFalse();
});

test('distant cousins and deep trees', function () {
    // Two lines of ten generations from one couple.
    $people = ['A0', 'B0'];
    $links = ['A0=B0'];

    foreach (['L', 'R'] as $side) {
        $parent = null;

        for ($generation = 1; $generation <= 10; $generation++) {
            $people[] = $person = "{$side}{$generation}";
            $links = $parent === null ? [...$links, "A0>{$person}", "B0>{$person}"] : [...$links, "{$parent}>{$person}"];
            $parent = $person;
        }
    }

    $graph = kinGraph($people, $links);

    expect(kinship('L10', 'R10', $graph))->toBe(['ninth cousin'])
        ->and(kinship('L10', 'R4', $graph))->toBe(['third cousin six times removed'])
        ->and(kinship('L3', 'R5', $graph))->toBe(['second cousin twice removed'])
        ->and(kinship('L10', 'A0', $graph))->toBe(['great-great-great-great-great-great-great-great-grandparent']);
});
