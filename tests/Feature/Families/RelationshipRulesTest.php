<?php

use App\Actions\Members\DeleteMember;
use App\Actions\Relationships\AddRelative;
use App\Actions\Relationships\ConnectSiblings;
use App\Enums\ParentSubtype;
use App\Enums\RelativeKind;
use App\Exceptions\InvalidRelationshipException;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\FamilyRelationship;
use App\Services\Genealogy\FamilyGraphLoader;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** Insert an edge straight into the table, bypassing every application check. */
function rawEdge(Family $family, string $type, FamilyMember $a, FamilyMember $b, string $subtype, ?string $status = null): void
{
    DB::table('family_relationships')->insert([
        'id' => (string) Str::uuid7(),
        'family_id' => $family->id,
        'type' => $type,
        'person_a_id' => $a->id,
        'person_b_id' => $b->id,
        'subtype' => $subtype,
        'status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// --- Friendly refusals from the application ------------------------------------------

test('a person cannot be their own parent', function () {
    [$family] = treeFamily();
    $ali = person($family, 'Ali');

    parentOf($ali, $ali);
})->throws(InvalidRelationshipException::class, 'themselves');

test('a child cannot become the parent of their own parent', function () {
    [$family] = treeFamily();
    $ahmad = person($family, 'Ahmad');
    $siti = person($family, 'Siti');
    parentOf($ahmad, $siti);

    parentOf($siti, $ahmad);
})->throws(InvalidRelationshipException::class, 'Siti cannot be a parent of Ahmad');

test('a descendant cannot become an ancestor (cycle), and the message names both people', function () {
    [$family] = treeFamily();
    $grandfather = person($family, 'Ahmad');
    $father = person($family, 'Bakar');
    $child = person($family, 'Siti');
    parentOf($grandfather, $father);
    parentOf($father, $child);

    expect(fn () => parentOf($child, $grandfather))
        ->toThrow(InvalidRelationshipException::class, 'Siti cannot be a parent of Ahmad, because Ahmad is already an ancestor of Siti');

    expect(FamilyRelationship::query()->count())->toBe(2);
});

test('a third biological parent is refused, but an adoptive parent is welcome', function () {
    [$family] = treeFamily();
    $child = person($family, 'Siti');
    parentOf(person($family, 'Father'), $child);
    parentOf(person($family, 'Mother'), $child);

    expect(fn () => parentOf(person($family, 'Another'), $child))
        ->toThrow(InvalidRelationshipException::class, 'already has two biological parents');

    parentOf(person($family, 'Adoptive Mother'), $child, ParentSubtype::Adoptive);
    parentOf(person($family, 'Step Father'), $child, ParentSubtype::Step);

    expect(FamilyRelationship::query()->where('person_b_id', $child->id)->count())->toBe(4);
});

test('the same parent link cannot be recorded twice', function () {
    [$family] = treeFamily();
    $parent = person($family, 'Ahmad');
    $child = person($family, 'Siti');
    parentOf($parent, $child);

    parentOf($parent, $child, ParentSubtype::Adoptive);
})->throws(InvalidRelationshipException::class, 'already recorded as a parent');

test('parent and child cannot be partners, and partners cannot become parent and child', function () {
    [$family] = treeFamily();
    $parent = person($family, 'Ahmad');
    $child = person($family, 'Siti');
    parentOf($parent, $child);

    expect(fn () => partners($parent, $child))->toThrow(InvalidRelationshipException::class, 'cannot also be partners');

    $husband = person($family, 'Husband');
    $wife = person($family, 'Wife');
    partners($husband, $wife);

    expect(fn () => parentOf($husband, $wife))->toThrow(InvalidRelationshipException::class, 'recorded as partners');
});

test('people from different families cannot be linked', function () {
    [$family] = treeFamily();
    [$other] = treeFamily();

    parentOf(person($family, 'Ahmad'), person($other, 'Stranger'));
})->throws(InvalidRelationshipException::class, 'same family tree');

// --- The model allows real family situations -----------------------------------------

test('several marriages, including concurrent ones, are allowed', function () {
    [$family] = treeFamily();
    $man = person($family, 'Haji Daud');

    partners($man, person($family, 'First Wife'));
    partners($man, person($family, 'Second Wife'));
    partners($man, person($family, 'Third Wife'), ['status' => 'divorced']);

    $graph = app(FamilyGraphLoader::class)->for($family);

    expect($graph->partnerIds($man->id))->toHaveCount(3)
        ->and(FamilyRelationship::query()->orderBy('sequence')->pluck('sequence')->all())->toBe([1, 2, 3]);
});

test('partners are stored in canonical order whichever way they are given', function () {
    [$family] = treeFamily();
    $one = person($family, 'One');
    $two = person($family, 'Two');

    $relationship = partners($two, $one);

    expect(strcmp($relationship->person_a_id, $relationship->person_b_id))->toBeLessThan(0);
});

test('a child may have only one known parent', function () {
    [$family] = treeFamily();
    $mother = person($family, 'Mother');
    $child = person($family, 'Child');
    parentOf($mother, $child);

    expect(app(FamilyGraphLoader::class)->for($family)->parentIds($child->id))->toBe([$mother->id]);
});

// --- Siblings ------------------------------------------------------------------------

test('connecting siblings links the new sibling to the same parents', function () {
    [$family, $owner] = treeFamily();
    $father = person($family, 'Father');
    $mother = person($family, 'Mother');
    $ali = person($family, 'Ali');
    $abu = person($family, 'Abu');
    parentOf($father, $ali);
    parentOf($mother, $ali);

    app(ConnectSiblings::class)->handle($family, $owner, $ali, $abu);

    $graph = app(FamilyGraphLoader::class)->for($family);

    expect($graph->parentIds($abu->id))->toEqualCanonicalizing([$father->id, $mother->id])
        ->and($graph->siblingIds($ali->id))->toBe([$abu->id]);
});

test('a half-sibling shares only the chosen parent', function () {
    [$family, $owner] = treeFamily();
    $father = person($family, 'Father');
    $mother = person($family, 'Mother');
    $ali = person($family, 'Ali');
    $half = person($family, 'Half Brother');
    parentOf($father, $ali);
    parentOf($mother, $ali);

    app(ConnectSiblings::class)->handle($family, $owner, $ali, $half, [$father->id]);

    expect(app(FamilyGraphLoader::class)->for($family)->parentIds($half->id))->toBe([$father->id]);
});

test('siblings with unknown parents are held together by a placeholder parent', function () {
    [$family, $owner] = treeFamily();
    $ali = person($family, 'Ali');
    $abu = person($family, 'Abu');

    app(ConnectSiblings::class)->handle($family, $owner, $ali, $abu);

    $placeholder = FamilyMember::query()->where('is_placeholder', true)->sole();
    $graph = app(FamilyGraphLoader::class)->for($family);

    expect($placeholder->full_name)->toBe('Unknown parent')
        ->and($graph->parentIds($ali->id))->toBe([$placeholder->id])
        ->and($graph->parentIds($abu->id))->toBe([$placeholder->id]);
});

test('connecting people who are already siblings is refused', function () {
    [$family, $owner] = treeFamily();
    $father = person($family, 'Father');
    $ali = person($family, 'Ali');
    $abu = person($family, 'Abu');
    parentOf($father, $ali);
    parentOf($father, $abu);

    app(ConnectSiblings::class)->handle($family, $owner, $ali, $abu);
})->throws(InvalidRelationshipException::class, 'already siblings');

// --- Adding a relative is all-or-nothing ---------------------------------------------

test('when the link is refused, the new person is not left behind', function () {
    [$family, $owner] = treeFamily();
    $child = person($family, 'Siti');
    parentOf(person($family, 'Father'), $child);
    parentOf(person($family, 'Mother'), $child);

    $before = FamilyMember::query()->count();

    expect(fn () => app(AddRelative::class)->handle($family, $owner, $child, RelativeKind::Parent, ['full_name' => 'Third Parent']))
        ->toThrow(InvalidRelationshipException::class);

    expect(FamilyMember::query()->count())->toBe($before);
});

test('adding a child with the other parent links both parents', function () {
    [$family, $owner] = treeFamily();
    $father = person($family, 'Father');
    $mother = person($family, 'Mother');
    partners($father, $mother);

    $child = app(AddRelative::class)->handle($family, $owner, $father, RelativeKind::Child, ['full_name' => 'Child'], ['other_parent_id' => $mother->id]);

    expect(app(FamilyGraphLoader::class)->for($family)->parentIds($child->id))->toEqualCanonicalizing([$father->id, $mother->id]);
});

// --- The database refuses impossible structures even when the application is bypassed --

test('database: nobody is their own parent or partner', function () {
    [$family] = treeFamily();
    $ali = person($family, 'Ali');

    rawEdge($family, 'parent_child', $ali, $ali, 'biological');
})->throws(QueryException::class, 'family_relationships_not_self_check');

test('database: a cycle is rejected by the trigger', function () {
    [$family] = treeFamily();
    $a = person($family, 'A');
    $b = person($family, 'B');
    $c = person($family, 'C');
    rawEdge($family, 'parent_child', $a, $b, 'biological');
    rawEdge($family, 'parent_child', $b, $c, 'biological');

    try {
        rawEdge($family, 'parent_child', $c, $a, 'biological');
        $this->fail('The cycle was accepted.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[0] ?? null)->toBe('MF001');
    }
});

test('database: a third biological parent is rejected by the trigger', function () {
    [$family] = treeFamily();
    $child = person($family, 'Child');
    rawEdge($family, 'parent_child', person($family, 'P1'), $child, 'biological');
    rawEdge($family, 'parent_child', person($family, 'P2'), $child, 'biological');

    try {
        rawEdge($family, 'parent_child', person($family, 'P3'), $child, 'biological');
        $this->fail('A third biological parent was accepted.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[0] ?? null)->toBe('MF002');
    }
});

test('database: parent and partner of the same person is rejected by the trigger', function () {
    [$family] = treeFamily();
    $a = person($family, 'A');
    $b = person($family, 'B');
    [$low, $high] = strcmp($a->id, $b->id) < 0 ? [$a, $b] : [$b, $a];
    rawEdge($family, 'partnership', $low, $high, 'marriage', 'current');

    try {
        rawEdge($family, 'parent_child', $a, $b, 'biological');
        $this->fail('Parent and partner was accepted.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[0] ?? null)->toBe('MF003');
    }
});

test('database: partners must be stored in canonical order', function () {
    [$family] = treeFamily();
    $a = person($family, 'A');
    $b = person($family, 'B');
    [$low, $high] = strcmp($a->id, $b->id) < 0 ? [$a, $b] : [$b, $a];

    rawEdge($family, 'partnership', $high, $low, 'marriage', 'current');
})->throws(QueryException::class, 'family_relationships_partner_order_check');

test('database: a subtype must match the kind of link', function () {
    [$family] = treeFamily();

    rawEdge($family, 'parent_child', person($family, 'A'), person($family, 'B'), 'marriage');
})->throws(QueryException::class, 'family_relationships_subtype_check');

test('database: people in different families cannot be linked', function () {
    [$family] = treeFamily();
    [$other] = treeFamily();

    rawEdge($family, 'parent_child', person($family, 'A'), person($other, 'Stranger'), 'biological');
})->throws(QueryException::class, 'family_relationships_person_b_id_foreign');

test('database: a death date requires the person to be marked deceased', function () {
    [$family] = treeFamily();
    $ali = person($family, 'Ali');

    DB::table('family_members')->where('id', $ali->id)->update(['death_date' => '1990-01-01', 'death_date_precision' => 'year']);
})->throws(QueryException::class, 'family_members_death_needs_deceased_check');

// --- Graph version and deletion --------------------------------------------------------

test('every change to the tree bumps the graph version', function () {
    [$family] = treeFamily();
    $version = fn (): int => $family->currentGraphVersion();

    $before = $version();
    $a = person($family, 'A');
    $b = person($family, 'B');
    expect($version())->toBeGreaterThan($before);

    $before = $version();
    $relationship = parentOf($a, $b);
    expect($version())->toBeGreaterThan($before);

    $before = $version();
    $relationship->delete();
    expect($version())->toBeGreaterThan($before);
});

test('removing a person removes their links and re-points the tree root', function () {
    [$family, $owner] = treeFamily();
    $father = person($family, 'Father');
    $child = person($family, 'Child');
    parentOf($father, $child);
    $family->forceFill(['root_member_id' => $father->id])->save();

    app(DeleteMember::class)->handle($father, $owner);

    expect(FamilyMember::query()->find($father->id))->toBeNull()
        ->and(FamilyMember::withTrashed()->find($father->id))->not->toBeNull()
        ->and(FamilyRelationship::query()->count())->toBe(0)
        ->and($family->refresh()->root_member_id)->toBe($child->id)
        ->and(app(FamilyGraphLoader::class)->for($family)->parentIds($child->id))->toBe([]);
});

test('generations are recomputed after the tree changes', function () {
    [$family] = treeFamily();
    $grandfather = person($family, 'Grandfather');
    $father = person($family, 'Father');
    $child = person($family, 'Child');
    $loner = person($family, 'Loner');

    parentOf($grandfather, $father);
    parentOf($father, $child);

    expect($grandfather->refresh()->generation)->toBe(1)
        ->and($father->refresh()->generation)->toBe(2)
        ->and($child->refresh()->generation)->toBe(3)
        ->and($loner->refresh()->generation)->toBeNull();
});
