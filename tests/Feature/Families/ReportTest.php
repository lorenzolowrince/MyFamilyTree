<?php

use App\Enums\FamilyRole;
use App\Enums\Gender;
use App\Enums\LivingStatus;
use App\Enums\Visibility;
use App\Jobs\GenerateReport;
use App\Jobs\RecomputeGenerations;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\Place;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Media\MediaLibrary;
use App\Services\Reports\HtmlToPdf;
use App\Services\Reports\ReportBuilder;
use App\Support\FuzzyDate\FuzzyDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

/** Pretend to be the PDF service: return a small PDF for any page. */
function fakePdfService(): void
{
    Http::fake(['*/forms/chromium/convert/html' => Http::response("%PDF-1.4\n% test report\n", 200, ['Content-Type' => 'application/pdf'])]);
}

/**
 * Lidau ═ Rumind → Gimbang (deceased) ═ Sarah → Daniel (living, born 1952 in Kota Kinabalu),
 * Maria (private) → Noah.
 *
 * @return array<string, FamilyMember>
 */
function reportTree(Family $family): array
{
    $kk = Place::resolve($family, 'Kota Kinabalu');
    $make = fn (string $name, Gender $gender, ?int $born, LivingStatus $status, array $extra = []) => person($family, $name, [
        'gender' => $gender, 'birth' => $born === null ? FuzzyDate::unknown() : FuzzyDate::day($born, 3, 14), 'living_status' => $status, ...$extra,
    ]);

    $people = [
        'lidau' => $make('Lidau bin Gunsalam', Gender::Male, 1898, LivingStatus::Deceased, ['death' => FuzzyDate::year(1971)]),
        'rumind' => $make('Rumind binti Sadang', Gender::Female, 1903, LivingStatus::Deceased),
        'gimbang' => $make('Gimbang Lidau', Gender::Male, 1925, LivingStatus::Deceased, ['occupation' => 'Farmer', 'biography' => 'Grew rice in Ranau.']),
        'sarah' => $make('Sarah binti Majin', Gender::Female, 1930, LivingStatus::Deceased),
        'daniel' => $make('Daniel Gimbang', Gender::Male, 1952, LivingStatus::Living, ['birth_place_id' => $kk->id, 'occupation' => 'Teacher']),
        'maria' => $make('Maria Gimbang', Gender::Female, 1955, LivingStatus::Living, ['visibility' => Visibility::Private]),
        'noah' => $make('Noah Maria', Gender::Male, 1982, LivingStatus::Living),
    ];

    partners($people['lidau'], $people['rumind']);
    parentOf($people['lidau'], $people['gimbang']);
    parentOf($people['rumind'], $people['gimbang']);
    partners($people['gimbang'], $people['sarah']);

    foreach (['daniel', 'maria'] as $child) {
        parentOf($people['gimbang'], $people[$child]);
        parentOf($people['sarah'], $people[$child]);
    }

    parentOf($people['maria'], $people['noah']);

    $family->forceFill(['root_member_id' => $people['lidau']->id])->save();
    RecomputeGenerations::dispatchSync($family->id);

    return $people;
}

/** The HTML a report would print, built as the given person. */
function reportHtml(Family $family, User $as, string $type, array $parameters = []): string
{
    $builder = app(ReportBuilder::class);
    $subject = isset($parameters['member_id']) ? FamilyMember::query()->find($parameters['member_id']) : null;

    $report = new ReportExport;
    $report->forceFill([
        'family_id' => $family->id, 'requested_by' => $as->id, 'type' => $type, 'title' => $builder->title($family, $type, $subject),
        'parameters' => $parameters, 'expires_at' => now()->addDay(),
    ]);

    return $builder->build($report, $as)['html'];
}

// --- Making and downloading ------------------------------------------------------------

test('a report is made in the background as a PDF, and only its requester can download it', function () {
    fakePdfService();
    [$family, $owner] = treeFamily();
    $people = reportTree($family);
    $admin = memberWithRole($family, FamilyRole::Admin);

    $this->actingAs($owner)
        ->post(route('families.reports.store', $family), ['type' => 'descendants', 'member_id' => $people['gimbang']->id, 'generations' => 3])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('families.reports.index', $family));

    $report = ReportExport::query()->sole();

    expect($report->status)->toBe('ready')
        ->and($report->title)->toBe('Descendants of Gimbang Lidau')
        ->and($report->parameters)->toEqual(['member_id' => $people['gimbang']->id, 'generations' => 3, 'made_as' => 'owner'])
        ->and($report->storage_key)->toBe("families/{$family->id}/reports/{$report->id}.pdf")
        ->and($report->expires_at->isAfter(now()->addDays(6)))->toBeTrue()
        ->and(Storage::disk('local')->exists((string) $report->storage_key))->toBeTrue();

    // The page Chromium printed was the report, with its footer, in portrait A4.
    Http::assertSent(function (ClientRequest $request): bool {
        $body = $request->body();

        return str_contains($body, 'Descendants of Gimbang Lidau')
            && str_contains($body, 'filename="footer.html"')
            && str_contains($body, 'name="landscape"'."\r\n\r\nfalse")
            && str_contains($body, 'name="paperWidth"'."\r\n\r\n8.27");
    });

    $this->get(route('families.reports.index', $family))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('reports/index')
        ->has('reports', 1)
        ->where('reports.0.status', 'ready')
        ->where('reports.0.download_url', route('families.reports.download', [$family, $report]))
        ->has('types', 6));

    $download = $this->get(route('families.reports.download', [$family, $report]))->assertOk();

    expect($download->headers->get('Content-Disposition'))->toStartWith('attachment;')->toContain('Descendants of Gimbang Lidau.pdf')
        ->and($download->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($download->headers->get('Content-Security-Policy'))->toContain('sandbox')
        ->and(DB::table('audit_logs')->whereIn('action', ['report.requested', 'report.downloaded'])->count())->toBe(2);

    // Someone else in the family: it does not exist for them, not even in their list.
    $this->actingAs($admin)->get(route('families.reports.download', [$family, $report]))->assertNotFound();
    $this->delete(route('families.reports.destroy', [$family, $report]))->assertNotFound();
    $this->get(route('families.reports.index', $family))->assertInertia(fn (AssertableInertia $page) => $page->has('reports', 0));

    // Removing it removes the file.
    $this->actingAs($owner)->delete(route('families.reports.destroy', [$family, $report]))->assertRedirect();

    expect(ReportExport::query()->count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('every kind of report can be made; the tree chart is printed landscape', function (string $type, array $input) {
    fakePdfService();
    [$family, $owner] = treeFamily();
    $people = reportTree($family);

    $data = ['type' => $type, ...array_map(fn ($value) => is_string($value) && isset($people[$value]) ? $people[$value]->id : $value, $input)];

    $this->actingAs($owner)->post(route('families.reports.store', $family), $data)->assertSessionHasNoErrors();

    expect(ReportExport::query()->sole()->status)->toBe('ready');

    Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->body(), 'name="landscape"'."\r\n\r\n".($type === 'tree' ? 'true' : 'false')));
})->with([
    'tree chart' => ['tree', ['generations' => 4]],
    'descendants' => ['descendants', ['member_id' => 'lidau', 'generations' => 8]],
    'ancestors' => ['ancestors', ['member_id' => 'noah', 'generations' => 4]],
    'individual' => ['individual', ['member_id' => 'gimbang']],
    'directory' => ['directory', []],
    'timeline' => ['timeline', ['from' => 1900, 'to' => 2000]],
]);

test('what a report needs is checked before anything is queued', function () {
    fakePdfService();
    [$family, $owner] = treeFamily();
    $people = reportTree($family);
    $editor = memberWithRole($family, FamilyRole::Editor);

    $send = fn (array $data, ?User $as = null) => $this->actingAs($as ?? $owner)->post(route('families.reports.store', $family), $data);

    $send(['type' => 'nonsense'])->assertSessionHasErrors('type');
    $send(['type' => 'individual'])->assertSessionHasErrors('member_id');
    $send(['type' => 'ancestors', 'member_id' => 'not-a-uuid'])->assertSessionHasErrors('member_id');
    $send(['type' => 'descendants', 'member_id' => $people['lidau']->id, 'generations' => 99])->assertSessionHasErrors('generations');
    $send(['type' => 'timeline', 'from' => 2000, 'to' => 1900])->assertSessionHasErrors('to');

    // A private person, asked about by someone who may not see them, is the same as nobody.
    $send(['type' => 'individual', 'member_id' => $people['maria']->id], $editor)->assertSessionHasErrors('member_id');

    // Another family's person.
    [$other] = treeFamily();
    $stranger = person($other, 'Stranger');
    $send(['type' => 'individual', 'member_id' => $stranger->id])->assertSessionHasErrors('member_id');

    expect(ReportExport::query()->count())->toBe(0);

    // At most three waiting at once per person.
    Http::fake(['*' => Http::response('busy', 503)]);
    config(['queue.default' => 'database']);

    foreach (range(1, 3) as $ignored) {
        $send(['type' => 'directory'])->assertSessionHasNoErrors();
    }

    $send(['type' => 'directory'])->assertSessionHasErrors('type');
    expect(ReportExport::query()->where('status', 'pending')->count())->toBe(3);
});

test('who may make reports, and outsiders find nothing', function () {
    fakePdfService();
    [$family] = treeFamily();
    reportTree($family);

    foreach ([FamilyRole::Admin, FamilyRole::Editor, FamilyRole::Contributor] as $role) {
        $this->actingAs(memberWithRole($family, $role))->get(route('families.reports.index', $family))->assertOk();
        $this->post(route('families.reports.store', $family), ['type' => 'directory'])->assertSessionHasNoErrors();
    }

    $viewer = memberWithRole($family, FamilyRole::Viewer);
    $this->actingAs($viewer)->get(route('families.reports.index', $family))->assertForbidden();
    $this->post(route('families.reports.store', $family), ['type' => 'directory'])->assertForbidden();

    $report = ReportExport::query()->firstOrFail();

    [$other, $outsider] = treeFamily();
    $this->actingAs($outsider)->get(route('families.reports.index', $family))->assertNotFound();
    $this->post(route('families.reports.store', $family), ['type' => 'directory'])->assertNotFound();
    $this->get(route('families.reports.download', [$family, $report]))->assertNotFound();
    $this->get(route('families.reports.download', [$other, $report]))->assertNotFound();

    expect(ReportExport::query()->count())->toBe(3);
});

test('after a change of role, a report made in the old role is no longer handed out', function () {
    fakePdfService();
    [$family] = treeFamily();
    reportTree($family);
    $admin = memberWithRole($family, FamilyRole::Admin);

    $this->actingAs($admin)->post(route('families.reports.store', $family), ['type' => 'directory'])->assertSessionHasNoErrors();
    $report = ReportExport::query()->sole();

    expect($report->parameters['made_as'])->toBe('admin');
    $this->get(route('families.reports.download', [$family, $report]))->assertOk();

    // Demoted: the report still holds what an administrator sees (private records).
    $admin->memberships()->sole()->forceFill(['role_id' => FamilyRole::Contributor->id()])->save();
    app()->forgetScopedInstances();
    $this->app['auth']->forgetGuards();

    $this->actingAs($admin->fresh())->get(route('families.reports.download', [$family, $report]))->assertNotFound();
    $this->get(route('families.reports.index', $family))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('reports.0.status', 'expired')
        ->where('reports.0.download_url', null));

    // A new one shows what a contributor may see, and can be downloaded.
    $this->post(route('families.reports.store', $family), ['type' => 'directory'])->assertSessionHasNoErrors();
    $fresh = ReportExport::query()->latest('id')->firstOrFail();

    expect($fresh->parameters['made_as'])->toBe('contributor');
    $this->get(route('families.reports.download', [$family, $fresh]))->assertOk();

    // The marker cannot be set from the form.
    $this->post(route('families.reports.store', $family), ['type' => 'directory', 'made_as' => 'owner', 'parameters' => ['made_as' => 'owner']]);
    expect(ReportExport::query()->latest('id')->firstOrFail()->parameters['made_as'])->toBe('contributor');
});

// --- Privacy ---------------------------------------------------------------------------

test('a report never shows more than its requester may see on screen', function () {
    [$family, $owner] = treeFamily();
    reportTree($family);
    $editor = memberWithRole($family, FamilyRole::Editor);
    $contributor = memberWithRole($family, FamilyRole::Contributor);

    $owners = reportHtml($family, $owner, 'directory');
    $editors = reportHtml($family, $editor, 'directory');
    $contributors = reportHtml($family, $contributor, 'directory');

    // The owner sees everyone, with living people's dates and places.
    expect($owners)->toContain('Maria Gimbang')->toContain('14 Mar 1952')->toContain('Kota Kinabalu')
        // An editor does not see the private person, but sees living people's details.
        ->and($editors)->not->toContain('Maria Gimbang')->toContain('14 Mar 1952')
        // A contributor sees neither the private person nor living people's dates and places…
        ->and($contributors)->not->toContain('Maria Gimbang')->not->toContain('1952')->not->toContain('Kota Kinabalu')
        // …but still sees the dead.
        ->and($contributors)->toContain('Lidau bin Gunsalam')->toContain('1898');

    // In a descendant outline, a hidden person keeps their place without their name.
    $outline = reportHtml($family, $editor, 'descendants', ['member_id' => FamilyMember::query()->where('full_name', 'Gimbang Lidau')->value('id'), 'generations' => 3]);

    expect($outline)->toContain('Private relative')->not->toContain('Maria Gimbang')->not->toContain('14 Mar 1955')->toContain('Noah Maria');

    // An individual summary of a living person, for a contributor: no dates, no occupation.
    $daniel = FamilyMember::query()->where('full_name', 'Daniel Gimbang')->value('id');
    $summary = reportHtml($family, $contributor, 'individual', ['member_id' => $daniel]);

    expect($summary)->toContain('Daniel Gimbang')->not->toContain('Teacher')->not->toContain('1952')
        ->toContain('Their dates, places and other personal details are not shown');
    expect(reportHtml($family, $owner, 'individual', ['member_id' => $daniel]))->toContain('Teacher')->toContain('14 Mar 1952');

    // Text from the family is printed as text, never as markup.
    FamilyMember::query()->where('full_name', 'Gimbang Lidau')->update(['biography' => '<script>alert(1)</script><img src=x onerror=alert(2)>']);
    $gimbang = reportHtml($family, $owner, 'individual', ['member_id' => FamilyMember::query()->where('full_name', 'Gimbang Lidau')->value('id')]);

    expect($gimbang)->not->toContain('<script>alert(1)')->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
});

test('a person who became hidden after the request is not reported on', function () {
    fakePdfService();
    config(['queue.default' => 'database']);
    [$family] = treeFamily();
    $people = reportTree($family);
    $editor = memberWithRole($family, FamilyRole::Editor);

    $this->actingAs($editor)->post(route('families.reports.store', $family), ['type' => 'individual', 'member_id' => $people['daniel']->id])->assertSessionHasNoErrors();

    // Before the report is made, Daniel is made private.
    $people['daniel']->forceFill(['visibility' => Visibility::Private])->save();
    app()->forgetScopedInstances();

    (new GenerateReport(ReportExport::query()->sole()->id))->handle(app(ReportBuilder::class), app(HtmlToPdf::class), app(MediaLibrary::class));

    $report = ReportExport::query()->sole();

    expect($report->status)->toBe('failed')
        ->and($report->error)->toContain('no longer')
        ->and($report->storage_key)->toBeNull();

    Http::assertNothingSent();
});

// --- Content ---------------------------------------------------------------------------

test('the reports contain the family as recorded', function () {
    [$family, $owner] = treeFamily();
    $people = reportTree($family);

    $ancestors = reportHtml($family, $owner, 'ancestors', ['member_id' => $people['noah']->id, 'generations' => 3]);

    // Noah is 1; his mother Maria is 3 (no father recorded); her parents 6 and 7; theirs 12 and 13.
    expect($ancestors)->toContain('Noah Maria')
        ->toMatch('/>\s*3\s*<\/td>\s*<td>\s*<strong>Maria Gimbang/')
        ->toMatch('/>\s*6\s*<\/td>\s*<td>\s*<strong>Gimbang Lidau/')
        ->toMatch('/>\s*7\s*<\/td>\s*<td>\s*<strong>Sarah binti Majin/')
        ->toMatch('/>\s*12\s*<\/td>\s*<td>\s*<strong>Lidau bin Gunsalam/')
        ->toContain('Great-grandparents');

    // Cousins who married: their grandparents appear on both sides, traced once.
    $adam = person($family, 'Adam Cousin');
    $rita = person($family, 'Rita Cousin', ['gender' => Gender::Female]);
    parentOf($people['lidau'], $rita);
    parentOf($people['noah'], $adam);
    parentOf($rita, $adam);

    // Adam 1, his mother Rita 3, her father Lidau 6; through his father Noah: Maria 5, Gimbang 10, Lidau again 20.
    $pedigree = reportHtml($family, $owner, 'ancestors', ['member_id' => $adam->id, 'generations' => 5]);

    expect($pedigree)->toMatch('/>\s*20\s*<\/td>\s*<td>\s*<strong>Lidau bin Gunsalam<\/strong>\s*<div class="muted small">Same person as no\. 6/')
        ->and(substr_count($pedigree, 'Rumind binti Sadang'))->toBe(1);

    $descendants = reportHtml($family, $owner, 'descendants', ['member_id' => $people['lidau']->id, 'generations' => 4]);

    expect($descendants)->toMatch('/1\.1\.2<\/span>\s*<strong>Maria Gimbang/')
        ->toMatch('/1\.1\.2\.1<\/span>\s*<strong>Noah Maria/')
        ->toContain('Rumind binti Sadang');

    $chart = reportHtml($family, $owner, 'tree', ['generations' => 4]);

    expect($chart)->toContain('<svg')->toContain('Lidau bin Gunsalam')->toContain('Noah Maria')->toContain('class="marriage"');

    $individual = reportHtml($family, $owner, 'individual', ['member_id' => $people['gimbang']->id]);

    expect($individual)->toContain('Farmer')->toContain('Grew rice in Ranau.')->toContain('Sarah binti Majin')->toContain('Daniel Gimbang')->toContain('Lidau bin Gunsalam');
});

// --- Expiry and the real PDF engine ----------------------------------------------------

test('expired reports and abandoned imports are deleted', function () {
    fakePdfService();
    [$family, $owner] = treeFamily();
    reportTree($family);

    $this->actingAs($owner)->post(route('families.reports.store', $family), ['type' => 'directory']);
    $report = ReportExport::query()->sole();

    $this->artisan('reports:purge')->assertSuccessful();
    expect(ReportExport::query()->count())->toBe(1);

    $this->travel(8)->days();

    $this->get(route('families.reports.index', $family))->assertInertia(fn (AssertableInertia $page) => $page->where('reports.0.status', 'expired')->where('reports.0.download_url', null));
    $this->get(route('families.reports.download', [$family, $report]))->assertNotFound();

    $this->artisan('reports:purge')->assertSuccessful();

    expect(ReportExport::query()->count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('Chromium prints a real report', function () {
    [$family, $owner] = treeFamily();
    reportTree($family);

    $report = new ReportExport;
    $report->forceFill(['family_id' => $family->id, 'requested_by' => $owner->id, 'type' => 'tree', 'title' => 'Family tree', 'parameters' => ['generations' => 4], 'expires_at' => now()->addDay()]);
    $built = app(ReportBuilder::class)->build($report, $owner);

    try {
        $pdf = app(HtmlToPdf::class)->render($built['html'], $built['footer'], $built['landscape'], $built['paper']);
    } catch (RuntimeException) {
        $this->markTestSkipped('The Gotenberg container is not running.');
    }

    expect($pdf)->toStartWith('%PDF')->and(strlen($pdf))->toBeGreaterThan(5000);
});
