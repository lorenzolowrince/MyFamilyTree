<?php

use App\Enums\FamilyRole;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\FamilyRelationship;
use App\Models\GedcomImport;
use App\Models\MemberName;
use App\Models\User;
use App\Services\Gedcom\GedcomDate;
use App\Services\Gedcom\GedcomParser;
use App\Services\Gedcom\GedcomReader;
use App\Support\FuzzyDate\FuzzyDate;
use Database\Seeders\DemoFamilySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

function exportGedcom($test, Family $family, User $as): string
{
    return $test->actingAs($as)->get(route('families.gedcom.export', $family))->assertOk()->streamedContent();
}

/**
 * Upload GEDCOM text and return the import once its preview is ready (jobs run at once in tests).
 */
function uploadGedcom($test, Family $family, User $as, string $gedcom, string $name = 'tree.ged'): GedcomImport
{
    $test->actingAs($as)
        ->post(route('families.gedcom.store', $family), ['file' => UploadedFile::fake()->createWithContent($name, $gedcom)])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    return GedcomImport::query()->where('family_id', $family->id)->latest('id')->firstOrFail();
}

/**
 * A tree as plain data that does not depend on ids: what "the same tree" means.
 *
 * @return array{people: array<string, mixed>, partnerships: list<mixed>, parents: list<mixed>}
 */
function treeShape(Family $family): array
{
    $members = FamilyMember::query()->where('family_id', $family->id)->with(['birthPlace', 'deathPlace', 'hometown', 'names'])->get()->keyBy('id');

    $people = $members->mapWithKeys(fn (FamilyMember $member): array => [$member->full_name => [
        'given' => $member->given_names, 'surname' => $member->surname, 'maiden' => $member->maiden_name, 'nickname' => $member->nickname,
        'gender' => $member->gender?->value, 'living' => $member->living_status->value,
        'birth' => $member->birth->label(), 'birth_text' => $member->birth->text, 'birth_place' => $member->birthPlace?->name,
        'death' => $member->death->label(), 'death_place' => $member->deathPlace?->name, 'hometown' => $member->hometown?->name,
        'occupation' => $member->occupation, 'education' => $member->education, 'nationality' => $member->nationality,
        'biography' => $member->biography, 'notes' => $member->notes, 'visibility' => $member->visibility->value,
        'placeholder' => $member->is_placeholder, 'generation' => $member->generation,
        'names' => $member->names->map(fn (MemberName $name): string => "{$name->type->value}:{$name->name}:{$name->notes}")->sort()->values()->all(),
    ]])->sortKeys()->all();

    $links = FamilyRelationship::query()->where('family_id', $family->id)->get();

    $partnerships = $links->where('type.value', 'partnership')->map(function (FamilyRelationship $link) use ($members): array {
        $pair = [$members[$link->person_a_id]->full_name, $members[$link->person_b_id]->full_name];
        sort($pair);

        return [...$pair, $link->subtype, $link->status?->value, $link->start->label(), $link->end->label(), $link->notes];
    })->sort()->values()->all();

    $parents = $links->where('type.value', 'parent_child')
        ->map(fn (FamilyRelationship $link): array => [$members[$link->person_a_id]->full_name, $members[$link->person_b_id]->full_name, $link->subtype, $link->certainty->value])
        ->sort()->values()->all();

    return compact('people', 'partnerships', 'parents');
}

// --- Dates and parsing -----------------------------------------------------------------

test('fuzzy dates survive the trip to GEDCOM and back', function (array $input, string $gedcom, ?string $phrase = null) {
    $date = FuzzyDate::fromInput($input);

    expect(GedcomDate::write($date))->toBe([$gedcom, $phrase]);

    $back = FuzzyDate::fromInput(GedcomDate::read($gedcom, $phrase));

    expect($back->toArray())->toBe($date->toArray());
})->with([
    'a day' => [['year' => 1998, 'month' => 7, 'day' => 15], '15 JUL 1998'],
    'a month' => [['year' => 1998, 'month' => 7], 'JUL 1998'],
    'a year' => [['year' => 1998], '1998'],
    'about' => [['year' => 1920, 'qualifier' => 'about'], 'ABT 1920'],
    'estimated' => [['year' => 1920, 'qualifier' => 'estimated'], 'EST 1920'],
    'calculated' => [['year' => 1920, 'month' => 3, 'qualifier' => 'calculated'], 'CAL MAR 1920'],
    'before' => [['year' => 1945, 'qualifier' => 'before'], 'BEF 1945'],
    'after' => [['year' => 1945, 'month' => 8, 'day' => 15, 'qualifier' => 'after'], 'AFT 15 AUG 1945'],
    'between' => [['year' => 1890, 'qualifier' => 'between', 'year_to' => 1895], 'BET 1890 AND 1895'],
    'a decade' => [['year' => 1920, 'decade' => true], 'BET 1920 AND 1929', '1920s'],
    'a year with words' => [['year' => 1943, 'text' => 'during the Japanese occupation'], '1943', 'during the Japanese occupation'],
    'only words' => [['text' => 'during the Japanese occupation'], '', 'during the Japanese occupation'],
    'unknown' => [[], ''],
]);

test('dates written by other programs are understood, and odd ones are kept as words', function () {
    $read = fn (string $value) => array_filter(GedcomDate::read($value), fn ($part) => $part !== null && $part !== false);

    expect($read('abt 1850'))->toBe(['year' => 1850, 'qualifier' => 'about'])
        ->and($read('@#DGREGORIAN@ 3 FEB 1901'))->toBe(['year' => 1901, 'month' => 2, 'day' => 3, 'qualifier' => 'exact'])
        ->and($read('FROM 1920 TO 1925'))->toBe(['year' => 1920, 'qualifier' => 'between', 'year_to' => 1925])
        ->and($read('BET 1 JAN 1900 AND 31 DEC 1905'))->toBe(['year' => 1900, 'month' => 1, 'day' => 1, 'qualifier' => 'between', 'year_to' => 1905])
        ->and($read('1750/51'))->toBe(['year' => 1750, 'qualifier' => 'exact'])
        ->and($read('(after the war)'))->toBe(['qualifier' => 'exact', 'text' => 'after the war'])
        ->and($read('INT 1925 (said to be the year of the flood)'))->toBe(['year' => 1925, 'qualifier' => 'exact', 'text' => 'said to be the year of the flood'])
        // Not a real date: nothing is invented.
        ->and($read('31 FEB 1900'))->toBe(['qualifier' => 'exact', 'text' => '31 FEB 1900'])
        ->and($read('Spring 1900'))->toBe(['qualifier' => 'exact', 'text' => 'Spring 1900'])
        ->and($read('2999'))->toBe(['qualifier' => 'exact', 'text' => '2999']);
});

test('the parser copes with files that are not quite standard', function () {
    $text = "\xEF\xBB\xBF0 HEAD\r\n1 GEDC\r\n2 VERS 5.5.1\r\n0 @I1@ INDI\r\n1 NAME Siti /Lidau/\r\n1 NOTE First line\r\n2 CONT second line\r\n2 CONC , joined\r\n"
        ."this line is rubbish\r\n   1 SEX F\r\n5 DEEP too deep\r\n1 NOTE @@handle\r\n0 TRLR\r\n";

    $parsed = (new GedcomParser)->parse($text);
    $person = $parsed['records'][1];

    expect($parsed['records'])->toHaveCount(3)
        ->and($person['xref'])->toBe('@I1@')
        ->and(GedcomParser::value($person, 'NAME'))->toBe('Siti /Lidau/')
        ->and(GedcomParser::value($person, 'SEX'))->toBe('F')
        ->and(GedcomParser::children($person, 'NOTE')[0]['value'])->toBe("First line\nsecond line, joined")
        ->and(GedcomParser::children($person, 'NOTE')[1]['value'])->toBe('@handle')
        ->and($parsed['warnings'])->toHaveCount(2);

    // Old Windows text is converted rather than turned into question marks.
    $latin = (new GedcomParser)->parse("0 HEAD\n1 CHAR ANSI\n0 @I1@ INDI\n1 NAME Jos\xE9 /Fern\xE1ndez/\n0 TRLR\n");

    expect(GedcomParser::value($latin['records'][1], 'NAME'))->toBe('José /Fernández/')
        ->and($latin['warnings'])->toHaveCount(1);
});

test('a file from another program is read: families, pedigrees, events', function () {
    $document = app(GedcomReader::class)->read(<<<'GED'
        0 HEAD
        1 SOUR OTHERAPP
        1 GEDC
        2 VERS 5.5.1
        0 @I1@ INDI
        1 NAME John /Smith/
        2 GIVN John
        1 NAME Jack /Smith/
        2 TYPE aka
        1 SEX M
        1 BIRT
        2 DATE ABT 1900
        2 PLAC Sandakan
        1 DEAT Y
        1 OCCU Clerk
        1 FAMS @F1@
        0 @I2@ INDI
        1 NAME Mary /Jones/
        1 SEX F
        1 FAMS @F1@
        0 @I3@ INDI
        1 NAME Ann /Smith/
        1 SEX F
        1 FAMC @F1@
        2 PEDI adopted
        0 @I4@ INDI
        1 NAME Tom /Smith/
        1 FAMC @F1@
        0 @F1@ FAM
        1 HUSB @I1@
        1 WIFE @I2@
        1 CHIL @I3@
        1 CHIL @I4@
        1 CHIL @I9@
        1 MARR
        2 DATE 12 JUN 1925
        2 PLAC Jesselton
        1 DIV
        2 DATE 1940
        0 @S1@ SOUR
        1 TITL Parish register
        0 TRLR
        GED);

    expect($document['people'])->toHaveCount(4)
        ->and($document['source'])->toBe('OTHERAPP')
        ->and($document['version'])->toBe('5.5.1')
        ->and($document['people']['@I1@']['data'])->toMatchArray([
            'full_name' => 'John Smith', 'surname' => 'Smith', 'given_names' => 'John', 'gender' => 'male',
            'living_status' => 'deceased', 'birth_place' => 'Sandakan', 'occupation' => 'Clerk',
        ])
        ->and($document['people']['@I1@']['data']['birth'])->toMatchArray(['year' => 1900, 'qualifier' => 'about'])
        ->and($document['people']['@I1@']['names'])->toBe([['type' => 'alias', 'name' => 'Jack Smith', 'notes' => null]])
        ->and($document['partnerships'])->toHaveCount(1)
        ->and($document['partnerships'][0]['details'])->toMatchArray(['subtype' => 'marriage', 'status' => 'divorced', 'place' => 'Jesselton'])
        ->and($document['partnerships'][0]['details']['start'])->toMatchArray(['year' => 1925, 'month' => 6, 'day' => 12])
        ->and($document['partnerships'][0]['details']['end'])->toMatchArray(['year' => 1940])
        ->and($document['parents'])->toHaveCount(4)
        ->and(collect($document['parents'])->where('child', '@I3@')->pluck('subtype')->unique()->all())->toBe(['adoptive'])
        ->and(collect($document['parents'])->where('child', '@I4@')->pluck('subtype')->unique()->values()->all())->toBe(['biological'])
        ->and(implode(' ', $document['warnings']))->toContain('child who is not in the file')->toContain('source record');
});

// --- Round trip ------------------------------------------------------------------------

test('exporting a tree and importing it into an empty family gives the same tree', function () {
    $this->seed(DemoFamilySeeder::class);

    $source = Family::query()->where('name', DemoFamilySeeder::FAMILY_NAME)->firstOrFail();
    $owner = User::query()->findOrFail($source->owner_id);
    $owner->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();

    // Details the seeder does not cover.
    $gimbang = FamilyMember::query()->where('family_id', $source->id)->where('full_name', 'Gimbang Lidau')->firstOrFail();
    $gimbang->forceFill([
        'surname' => 'Lidau', 'given_names' => 'Gimbang', 'nickname' => 'Aki', 'occupation' => 'Farmer', 'education' => 'Mission school',
        'nationality' => 'Malaysian', 'biography' => "Born in the kampung.\nMoved to town in 1950.", 'notes' => '@source: baptism register', 'visibility' => 'restricted',
    ])->save();
    $gimbang->birth = FuzzyDate::fromInput(['year' => 1920, 'decade' => true]);
    $gimbang->save();

    foreach ([['religious', 'Petrus', 'Baptismal name'], ['alias', 'G. Lidau', null]] as [$type, $value, $notes]) {
        $name = new MemberName;
        $name->forceFill(['family_id' => $source->id, 'member_id' => $gimbang->id, 'type' => $type, 'name' => $value, 'notes' => $notes])->save();
    }

    $gedcom = exportGedcom($this, $source, $owner);

    expect($gedcom)->toStartWith("0 HEAD\n1 GEDC\n2 VERS 7.0\n")
        ->toEndWith("0 TRLR\n")
        ->toContain('1 NAME Gimbang /Lidau/')
        ->toContain("1 NOTE Born in the kampung.\n2 CONT Moved to town in 1950.")
        ->toContain('1 _RESEARCH @@source: baptism register')
        ->toContain("2 DATE BET 1920 AND 1929\n3 PHRASE 1920s")
        ->toContain('1 RESN CONFIDENTIAL')
        ->toContain('2 _MREL step')
        ->and(substr_count($gedcom, ' INDI'))->toBe(15)
        ->and(DB::table('audit_logs')->where('action', 'gedcom.exported')->count())->toBe(1);

    $target = Family::factory()->create(['owner_id' => $owner->id]);
    $import = uploadGedcom($this, $target, $owner, $gedcom);

    expect($import->status)->toBe('preview_ready')
        ->and($import->summary['counts'])->toEqual(['people' => 15, 'partnerships' => 5, 'parent_links' => 20, 'possible_duplicates' => 0])
        ->and($import->summary['warnings'])->toBe([])
        ->and(FamilyMember::query()->where('family_id', $target->id)->count())->toBe(0);   // nothing happens before confirmation

    $this->post(route('families.gedcom.confirm', [$target, $import]), ['decisions' => []])->assertSessionHasNoErrors();

    $import->refresh();

    expect($import->status)->toBe('completed')
        ->and($import->result)->toEqual(['created' => 15, 'merged' => 0, 'skipped' => 0, 'partnerships' => 5, 'parent_links' => 20, 'problems' => []])
        ->and(treeShape($target))->toEqual(treeShape($source))
        ->and(Storage::disk('local')->allFiles())->toBe([])
        // One line in each history for the whole import.
        ->and(DB::table('audit_logs')->where('family_id', $target->id)->pluck('action')->all())->toBe(['gedcom.imported'])
        ->and(DB::table('activity_logs')->where('family_id', $target->id)->value('summary'))->toBe('imported 15 people from a GEDCOM file');

    // And out again: the same file, apart from the day it was written.
    expect(exportGedcom($this, $target, $owner))->toBe($gedcom);
});

// --- Import into a tree that already has people ----------------------------------------

test('people already in the tree are offered as matches; merging never overwrites', function () {
    [$family, $owner] = treeFamily();
    $john = person($family, 'John Smith', ['birth' => FuzzyDate::year(1900), 'occupation' => 'Farmer', 'gender' => null]);
    $other = person($family, 'Mary Jonas');

    $gedcom = <<<'GED'
        0 HEAD
        1 GEDC
        2 VERS 7.0
        0 @I1@ INDI
        1 NAME John /Smith/
        1 SEX M
        1 BIRT
        2 DATE 1900
        2 PLAC Sandakan
        1 OCCU Clerk
        1 DEAT
        2 DATE 1960
        0 @I2@ INDI
        1 NAME Mary /Jones/
        1 SEX F
        0 @I3@ INDI
        1 NAME Ann /Smith/
        0 @I4@ INDI
        1 NAME Unwanted /Person/
        0 @F1@ FAM
        1 HUSB @I1@
        1 WIFE @I2@
        1 CHIL @I3@
        1 CHIL @I4@
        1 MARR Y
        0 TRLR
        GED;

    $import = uploadGedcom($this, $family, $owner, $gedcom);
    $people = collect($import->summary['people'])->keyBy('xref');

    // Close names are offered too ("Ann Smith" might be "John Smith" mistyped): the reviewer decides.
    expect($import->summary['counts']['possible_duplicates'])->toBe(3)
        ->and($people['@I1@']['matches'][0]['id'])->toBe($john->id)
        ->and($people['@I2@']['matches'][0]['id'])->toBe($other->id)
        ->and($people['@I4@']['matches'])->toBe([])
        // Same name and year: suggested as the same person. A similar name only: left to the reviewer.
        ->and($import->summary['suggested'])->toBe(['@I1@' => $john->id]);

    $confirm = fn (array $decisions) => $this->post(route('families.gedcom.confirm', [$family, $import]), ['decisions' => $decisions]);

    // Not one of the people offered for that entry; and one person cannot be matched twice.
    $confirm(['@I4@' => $john->id])->assertSessionHasErrors('decisions');
    $confirm(['@I1@' => $john->id, '@I2@' => $john->id])->assertSessionHasErrors('decisions');
    expect($import->refresh()->status)->toBe('preview_ready');

    $confirm(['@I1@' => $john->id, '@I2@' => 'create', '@I3@' => 'create', '@I4@' => 'skip'])->assertSessionHasNoErrors();

    $john->refresh();

    expect($import->refresh()->result)->toMatchArray(['created' => 2, 'merged' => 1, 'skipped' => 1, 'partnerships' => 1, 'parent_links' => 2])
        ->and(FamilyMember::query()->where('family_id', $family->id)->count())->toBe(4)
        ->and($john->occupation)->toBe('Farmer')                       // what the family recorded stays
        ->and($john->birthPlace?->name)->toBe('Sandakan')              // what was missing is added
        ->and($john->living_status->value)->toBe('deceased')
        ->and($john->death->yearValue())->toBe(1960)
        ->and($john->gender?->value)->toBe('male')
        ->and(FamilyMember::query()->where('full_name', 'Unwanted Person')->exists())->toBeFalse()
        ->and(FamilyRelationship::query()->where('family_id', $family->id)->where('person_a_id', $john->id)->orWhere('person_b_id', $john->id)->count())->toBe(2);

    // Importing the same file again adds nobody and no link twice.
    $again = uploadGedcom($this, $family, $owner, $gedcom);
    $suggested = $again->summary['suggested'];

    expect($suggested)->toHaveCount(3);

    $this->post(route('families.gedcom.confirm', [$family, $again]), ['decisions' => [...$suggested, '@I4@' => 'skip']])->assertSessionHasNoErrors();

    expect($again->refresh()->result)->toMatchArray(['created' => 0, 'merged' => 3, 'skipped' => 1, 'partnerships' => 0, 'parent_links' => 0])
        ->and(FamilyMember::query()->where('family_id', $family->id)->count())->toBe(4)
        ->and(FamilyRelationship::query()->where('family_id', $family->id)->count())->toBe(3);
});

test('links the tree cannot hold are skipped and reported; the rest is imported', function () {
    [$family, $owner] = treeFamily();

    // A is B's parent and B is A's parent; C has three biological parents.
    $import = uploadGedcom($this, $family, $owner, <<<'GED'
        0 HEAD
        1 GEDC
        2 VERS 7.0
        0 @I1@ INDI
        1 NAME Loop /One/
        0 @I2@ INDI
        1 NAME Loop /Two/
        0 @I3@ INDI
        1 NAME Three /Parents/
        0 @I4@ INDI
        1 NAME Extra /Parent/
        0 @F1@ FAM
        1 HUSB @I1@
        1 CHIL @I2@
        0 @F2@ FAM
        1 HUSB @I2@
        1 CHIL @I1@
        0 @F3@ FAM
        1 HUSB @I1@
        1 WIFE @I2@
        1 CHIL @I3@
        0 @F4@ FAM
        1 HUSB @I4@
        1 CHIL @I3@
        0 TRLR
        GED);

    $this->post(route('families.gedcom.confirm', [$family, $import]), ['decisions' => []])->assertSessionHasNoErrors();

    $result = $import->refresh()->result;

    expect($import->status)->toBe('completed')
        ->and($result['created'])->toBe(4)
        ->and($result['problems'])->not->toBe([])
        ->and(implode(' ', $result['problems']))->toContain('Loop')
        ->and(FamilyMember::query()->where('family_id', $family->id)->count())->toBe(4)
        // No loop and no third biological parent made it in.
        ->and(FamilyRelationship::query()->where('family_id', $family->id)->where('type', 'parent_child')->where('person_b_id', FamilyMember::query()->where('full_name', 'Three Parents')->value('id'))->count())->toBe(2);
});

// --- What is accepted, and from whom ---------------------------------------------------

test('only GEDCOM files are accepted, and a file that cannot be used says why', function () {
    [$family, $owner] = treeFamily();

    $upload = fn (string $content, string $name = 'tree.ged') => $this->actingAs($owner)
        ->post(route('families.gedcom.store', $family), ['file' => UploadedFile::fake()->createWithContent($name, $content)]);

    $upload('<?php echo 1;')->assertSessionHasErrors('file');
    $upload('%PDF-1.4 not a tree', 'tree.pdf')->assertSessionHasErrors('file');
    $upload('')->assertSessionHasErrors('file');
    $this->post(route('families.gedcom.store', $family), [])->assertSessionHasErrors('file');

    expect(GedcomImport::query()->count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);

    // A header and nothing else.
    $upload("0 HEAD\n1 GEDC\n2 VERS 7.0\n0 TRLR\n")->assertRedirect();
    $empty = GedcomImport::query()->sole();

    expect($empty->status)->toBe('failed')->and($empty->error)->toContain('No people were found');

    $this->post(route('families.gedcom.confirm', [$family, $empty]), ['decisions' => []])->assertRedirect(route('families.gedcom.show', [$family, $empty]));
    expect($empty->refresh()->status)->toBe('failed');
    $this->delete(route('families.gedcom.cancel', [$family, $empty]))->assertRedirect(route('families.gedcom.index', $family));

    // Cancelling a preview removes the file and changes nothing.
    $import = uploadGedcom($this, $family, $owner, "0 HEAD\n0 @I1@ INDI\n1 NAME Siti /Lidau/\n0 TRLR\n");
    expect($import->status)->toBe('preview_ready');

    $this->delete(route('families.gedcom.cancel', [$family, $import]))->assertRedirect();

    expect($import->refresh()->status)->toBe('cancelled')
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(FamilyMember::query()->where('family_id', $family->id)->count())->toBe(0);

    $this->post(route('families.gedcom.confirm', [$family, $import]), ['decisions' => []])->assertRedirect();
    expect($import->refresh()->status)->toBe('cancelled');
});

test('export and import are for the owner and administrators only', function () {
    [$family, $owner] = treeFamily();
    person($family, 'Ahmad');
    $file = fn () => UploadedFile::fake()->createWithContent('tree.ged', "0 HEAD\n0 @I1@ INDI\n1 NAME Siti\n0 TRLR\n");

    $import = uploadGedcom($this, $family, $owner, "0 HEAD\n0 @I1@ INDI\n1 NAME Siti\n0 TRLR\n");

    foreach ([FamilyRole::Editor, FamilyRole::Contributor, FamilyRole::Viewer] as $role) {
        $this->actingAs(memberWithRole($family, $role));
        $this->get(route('families.gedcom.index', $family))->assertForbidden();
        $this->get(route('families.gedcom.export', $family))->assertForbidden();
        $this->post(route('families.gedcom.store', $family), ['file' => $file()])->assertForbidden();
        $this->get(route('families.gedcom.show', [$family, $import]))->assertForbidden();
        $this->post(route('families.gedcom.confirm', [$family, $import]), ['decisions' => []])->assertForbidden();
        $this->delete(route('families.gedcom.cancel', [$family, $import]))->assertForbidden();
    }

    $this->actingAs(memberWithRole($family, FamilyRole::Admin));
    $this->get(route('families.gedcom.export', $family))->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

    [$other, $outsider] = treeFamily();
    $this->actingAs($outsider);
    $this->get(route('families.gedcom.export', $family))->assertNotFound();
    $this->post(route('families.gedcom.store', $family), ['file' => $file()])->assertNotFound();
    // Another family's import cannot be reached through one's own family.
    $this->get(route('families.gedcom.show', [$other, $import]))->assertNotFound();
    $this->post(route('families.gedcom.confirm', [$other, $import]), ['decisions' => []])->assertNotFound();

    expect($import->refresh()->status)->toBe('preview_ready');
});

test('the import pages show the preview and the result', function () {
    [$family, $owner] = treeFamily();
    person($family, 'Siti Lidau');

    $import = uploadGedcom($this, $family, $owner, "0 HEAD\n1 SOUR OTHERAPP\n0 @I1@ INDI\n1 NAME Siti /Lidau/\n0 @I2@ INDI\n1 NAME Zainab /Wong/\n0 @X1@ SOUR\n0 TRLR\n", 'keluarga.ged');

    $this->get(route('families.gedcom.show', [$family, $import]))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('gedcom/show')
        ->where('import.status', 'preview_ready')
        ->where('import.filename', 'keluarga.ged')
        ->where('import.source', 'OTHERAPP')
        ->where('import.counts.people', 2)
        ->has('import.duplicates', 1)
        ->where('import.duplicates.0.name', 'Siti Lidau')
        ->has('import.sample', 1)
        ->has('import.warnings', 1));

    $this->get(route('families.gedcom.index', $family))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('gedcom/index')
        ->where('members', 1)
        ->has('imports', 1)
        ->where('can.import', true));
});
