<?php

use App\Enums\FamilyRole;
use App\Enums\MediaStatus;
use App\Jobs\ProcessMediaUpload;
use App\Models\Family;
use App\Models\FamilyDocument;
use App\Models\FamilyPhoto;
use App\Models\PhotoAlbum;
use App\Models\TimelineEntry;
use App\Models\User;
use App\Notifications\TaggedInPhoto;
use App\Notifications\UploadRejected;
use App\Services\Media\ClamAvScanner;
use App\Services\Media\MediaProcessor;
use App\Services\Media\ScannerUnavailable;
use App\Services\Media\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
});

const EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

/** A file from tests/Fixtures, arriving under the given name. */
function sampleFile(string $file, ?string $as = null): UploadedFile
{
    return new UploadedFile(base_path("tests/Fixtures/{$file}"), $as ?? $file, null, null, true);
}

/** A real JPEG; different sizes give different files. */
function jpeg(int $width = 640, int $height = 480, string $name = 'photo.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name, $width, $height);
}

/** A scanner that flags the standard antivirus test string, like ClamAV does. */
function fakeScanner(): void
{
    app()->bind(VirusScanner::class, fn () => new class implements VirusScanner
    {
        public function scan(string $path): ?string
        {
            return str_contains((string) file_get_contents($path), 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE') ? 'Eicar-Test-Signature' : null;
        }
    });
}

/**
 * @param  array<string, mixed>  $details
 */
function uploadPhoto($test, Family $family, User $as, ?UploadedFile $file = null, array $details = []): FamilyPhoto
{
    $test->actingAs($as)
        ->post(route('families.photos.store', $family), ['files' => [$file ?? jpeg()], ...$details])
        ->assertSessionHasNoErrors();

    return FamilyPhoto::query()->latest('id')->firstOrFail();
}

/**
 * @param  array<string, mixed>  $details
 */
function uploadDocument($test, Family $family, User $as, ?UploadedFile $file = null, array $details = []): FamilyDocument
{
    $test->actingAs($as)
        ->post(route('families.documents.store', $family), ['files' => [$file ?? sampleFile('two-pages.pdf', 'Birth certificate.pdf')], ...$details])
        ->assertSessionHasNoErrors();

    return FamilyDocument::query()->latest('id')->firstOrFail();
}

// --- The safety pipeline ---------------------------------------------------------------

test('an uploaded photo is re-encoded, loses its location data, and only then is shown', function () {
    [$family, $owner] = treeFamily();
    $contributor = memberWithRole($family, FamilyRole::Contributor);

    $photo = uploadPhoto($this, $family, $contributor, sampleFile('photo-with-gps.jpg', '../../IMG 0001.JPG'));
    $folder = "families/{$family->id}/photos/{$photo->id}";

    expect($photo->status)->toBe(MediaStatus::Ready)
        ->and($photo->original_filename)->toBe('IMG 0001.JPG')          // a label only
        ->and($photo->storage_key)->toBe("{$folder}/original.jpg")     // never built from the upload's name
        ->and($photo->mime_type)->toBe('image/jpeg')
        // Turned upright (the camera's orientation tag is removed with the rest).
        ->and([$photo->width, $photo->height])->toBe([600, 800])
        // The one detail kept: when it was taken.
        ->and($photo->taken->date?->toDateString())->toBe('1998-07-15');

    Storage::disk('local')->assertMissing("{$folder}/quarantine");
    Storage::disk('local')->assertExists(["{$folder}/original.jpg", "{$folder}/display.jpg", "{$folder}/thumb.jpg"]);

    $response = $this->actingAs($owner)->get(route('families.photos.file', [$family, $photo, 'original']))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $bytes = $response->streamedContent();
    $served = new Imagick;
    $served->readImageBlob($bytes);

    expect($served->getImageProperties('exif:*'))->toBe([])
        ->and($bytes)->not->toContain('TestCam')
        ->and(file_get_contents(base_path('tests/Fixtures/photo-with-gps.jpg')))->toContain('TestCam');

    $stored = array_sum(array_map(fn (string $key): int => Storage::disk('local')->size($key), $photo->storageKeys()));

    expect($photo->size_bytes)->toBe($stored)
        ->and($family->refresh()->storage_bytes_used)->toBe($stored)
        ->and(DB::table('activity_logs')->where('verb', 'photo.uploaded')->where('user_id', $contributor->id)->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'photo.uploaded')->count())->toBe(1);
});

test('the type of a file is decided from its content, not its name', function () {
    [$family, $owner] = treeFamily();

    $refused = [
        UploadedFile::fake()->createWithContent('holiday.jpg', '<?php system($_GET["c"]);'),
        UploadedFile::fake()->createWithContent('holiday.png', "MZ\x90\x00".str_repeat("\x00", 200)),
        UploadedFile::fake()->createWithContent('page.jpg', '<html><script>alert(1)</script></html>'),
        UploadedFile::fake()->createWithContent('drawing.jpg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        sampleFile('two-pages.pdf', 'scan.jpg'),
    ];

    foreach ($refused as $file) {
        $this->actingAs($owner)->post(route('families.photos.store', $family), ['files' => [$file]])->assertSessionHasErrors('files');
    }

    $this->post(route('families.documents.store', $family), ['files' => [UploadedFile::fake()->createWithContent('will.pdf', '<?php echo 1;')]])
        ->assertSessionHasErrors('files');
    $this->post(route('families.photos.store', $family), ['files' => []])->assertSessionHasErrors('files');

    expect(FamilyPhoto::query()->count())->toBe(0)
        ->and(FamilyDocument::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and($family->refresh()->storage_bytes_used)->toBe(0);
});

test('a file the virus scanner flags is deleted and its uploader is told', function () {
    fakeScanner();
    [$family, $owner] = treeFamily();
    $contributor = memberWithRole($family, FamilyRole::Contributor);

    $clean = jpeg();
    $infected = UploadedFile::fake()->createWithContent('party.jpg', file_get_contents($clean->getRealPath()).EICAR);
    $photo = uploadPhoto($this, $family, $contributor, $infected);

    expect($photo->status)->toBe(MediaStatus::Rejected)
        ->and($photo->rejection_reason)->toContain('virus scanner')
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and($family->refresh()->storage_bytes_used)->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'photo.malware_found')->count())->toBe(1);

    Notification::assertSentTo($contributor, UploadRejected::class);

    // Nobody else ever sees it; the uploader sees why and can dismiss it.
    $this->actingAs($owner)->get(route('families.photos.index', $family))->assertInertia(fn (AssertableInertia $page) => $page->has('photos.data', 0));
    $this->get(route('families.photos.show', [$family, $photo]))->assertNotFound();

    $this->actingAs($contributor)->get(route('families.photos.index', $family))->assertInertia(fn (AssertableInertia $page) => $page
        ->has('photos.data', 1)
        ->where('photos.data.0.status', 'rejected')
        ->where('photos.data.0.urls', null));
    $this->get(route('families.photos.file', [$family, $photo, 'thumb']))->assertNotFound();

    $this->delete(route('families.photos.destroy', [$family, $photo]))->assertRedirect();
    expect(FamilyPhoto::query()->withTrashed()->count())->toBe(0);

    // A clean file goes through the same scanner.
    expect(uploadPhoto($this, $family, $contributor, jpeg(300, 300))->status)->toBe(MediaStatus::Ready);
});

test('while the scanner is unreachable a file stays in quarantine and is never served', function () {
    Queue::fake();
    [$family, $owner] = treeFamily();
    $editor = memberWithRole($family, FamilyRole::Editor);

    $photo = uploadPhoto($this, $family, $owner);
    Queue::assertPushed(ProcessMediaUpload::class);

    app()->bind(VirusScanner::class, fn () => new ClamAvScanner('127.0.0.1', 1, 1));

    expect(fn () => app(MediaProcessor::class)->process($photo))->toThrow(ScannerUnavailable::class);
    expect($photo->refresh()->status)->toBe(MediaStatus::Quarantined);

    $this->actingAs($editor)->get(route('families.photos.show', [$family, $photo]))->assertNotFound();
    $this->actingAs($owner)->get(route('families.photos.file', [$family, $photo, 'original']))->assertNotFound();
    $this->get(route('families.photos.index', $family))->assertInertia(fn (AssertableInertia $page) => $page->where('photos.data.0.status', 'quarantined')->where('photos.data.0.urls', null));

    // When the retries run out the file is refused, not released.
    (new ProcessMediaUpload('photo', $photo->id))->failed(null);

    expect($photo->refresh()->status)->toBe(MediaStatus::Rejected)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and($family->refresh()->storage_bytes_used)->toBe(0);
});

test('ClamAV itself detects the antivirus test file', function () {
    $scanner = new ClamAvScanner((string) config('media.scanner.host'), (int) config('media.scanner.port'), 30);
    $clean = tempnam(sys_get_temp_dir(), 'clean');
    $infected = tempnam(sys_get_temp_dir(), 'eicar');
    file_put_contents($clean, 'An ordinary file.');
    file_put_contents($infected, EICAR);

    try {
        expect($scanner->scan($clean))->toBeNull();
    } catch (ScannerUnavailable) {
        $this->markTestSkipped('The ClamAV container is not running (or still loading its database).');
    }

    expect($scanner->scan($infected))->toContain('Eicar');
});

test('a HEIC photo from a phone is converted to JPEG', function () {
    $source = tempnam(sys_get_temp_dir(), 'heic');

    try {
        $image = new Imagick;
        $image->newImage(320, 240, 'green');
        $image->setImageFormat('heic');
        $image->writeImage('heic:'.$source);
    } catch (ImagickException) {
        $this->markTestSkipped('This ImageMagick build cannot write HEIC files.');
    }

    if (filesize($source) === 0 || MediaProcessor::detectType($source) !== 'image/heic') {
        $this->markTestSkipped('This ImageMagick build cannot write HEIC files.');
    }

    [$family, $owner] = treeFamily();
    $photo = uploadPhoto($this, $family, $owner, new UploadedFile($source, 'IMG_2041.HEIC', null, null, true));

    expect($photo->status)->toBe(MediaStatus::Ready)->and($photo->mime_type)->toBe('image/jpeg')->and($photo->width)->toBe(320);
});

// --- Storage allowance -----------------------------------------------------------------

test('the storage allowance is enforced, and identical files are not stored twice', function () {
    [$family, $owner] = treeFamily();
    $first = jpeg(800, 600);
    $family->forceFill(['storage_quota_bytes' => filesize($first->getRealPath()) + 100])->save();

    $photo = uploadPhoto($this, $family, $owner, $first);
    $used = $family->refresh()->storage_bytes_used;

    expect($used)->toBe($photo->size_bytes)->and($used)->toBeGreaterThan(0);

    // The same bytes again: already there.
    $this->post(route('families.photos.store', $family), ['files' => [jpeg(800, 600)]])->assertSessionHasErrors('files');

    // A different file that no longer fits.
    $family->forceFill(['storage_quota_bytes' => $used + 10])->save();
    $this->post(route('families.photos.store', $family), ['files' => [jpeg(900, 700)]])->assertSessionHasErrors('files');
    $this->post(route('families.documents.store', $family), ['files' => [sampleFile('two-pages.pdf')]])->assertSessionHasErrors('files');

    expect(FamilyPhoto::query()->count())->toBe(1)
        ->and(FamilyDocument::query()->count())->toBe(0)
        ->and($family->refresh()->storage_bytes_used)->toBe($used);

    $this->get(route('families.edit', $family))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('family.storage.used', $used)
        ->where('family.storage.photos', $used)
        ->where('family.storage.quota', $used + 10));
});

test('a deleted file can no longer be seen, and its space returns when it is erased', function () {
    [$family, $owner] = treeFamily();
    $photo = uploadPhoto($this, $family, $owner);
    $document = uploadDocument($this, $family, $owner);
    $used = $family->refresh()->storage_bytes_used;

    expect($used)->toBe($photo->size_bytes + $document->size_bytes);

    $this->delete(route('families.photos.destroy', [$family, $photo]))->assertRedirect(route('families.photos.index', $family));
    $this->delete(route('families.documents.destroy', [$family, $document]))->assertRedirect(route('families.documents.index', $family));

    $this->get(route('families.photos.show', [$family, $photo]))->assertNotFound();
    $this->get(route('families.photos.file', [$family, $photo, 'thumb']))->assertNotFound();
    $this->get(route('families.documents.download', [$family, $document]))->assertNotFound();

    // Kept for 30 days in case it was a mistake.
    $this->artisan('media:purge')->assertSuccessful();
    expect($family->refresh()->storage_bytes_used)->toBe($used)->and(Storage::disk('local')->allFiles())->not->toBe([]);

    $this->travel(31)->days();
    $this->artisan('media:purge')->assertSuccessful();

    expect($family->refresh()->storage_bytes_used)->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(FamilyPhoto::query()->withTrashed()->count())->toBe(0)
        ->and(FamilyDocument::query()->withTrashed()->count())->toBe(0)
        ->and(DB::table('audit_logs')->whereIn('action', ['photo.deleted', 'document.deleted'])->count())->toBe(2);
});

// --- Who may see and do what -----------------------------------------------------------

test('who may upload, and outsiders find nothing', function () {
    [$family] = treeFamily();
    [, $outsider] = treeFamily();

    $upload = fn (FamilyRole $role, string $route) => $this->actingAs(memberWithRole($family, $role))
        ->post(route($route, $family), ['files' => [$route === 'families.photos.store' ? jpeg(random_int(100, 900), random_int(100, 900)) : sampleFile('two-pages.pdf')]])
        ->status();

    expect($upload(FamilyRole::Viewer, 'families.photos.store'))->toBe(403)
        ->and($upload(FamilyRole::Viewer, 'families.documents.store'))->toBe(403)
        ->and($upload(FamilyRole::Contributor, 'families.photos.store'))->toBe(302)
        ->and($upload(FamilyRole::Contributor, 'families.documents.store'))->toBe(302)
        ->and($upload(FamilyRole::Editor, 'families.photos.store'))->toBe(302);

    $photo = FamilyPhoto::query()->firstOrFail();
    $document = FamilyDocument::query()->firstOrFail();

    $this->actingAs($outsider);

    foreach ([
        route('families.photos.index', $family), route('families.photos.show', [$family, $photo]), route('families.photos.file', [$family, $photo, 'display']),
        route('families.documents.index', $family), route('families.documents.show', [$family, $document]), route('families.documents.page', [$family, $document, 1]),
        route('families.documents.thumb', [$family, $document]), route('families.documents.download', [$family, $document]),
    ] as $url) {
        $this->get($url)->assertNotFound();
    }

    $this->post(route('families.photos.store', $family), ['files' => [jpeg()]])->assertNotFound();
    $this->patch(route('families.photos.update', [$family, $photo]), ['caption' => 'Mine now'])->assertNotFound();
    $this->delete(route('families.documents.destroy', [$family, $document]))->assertNotFound();

    // A photo of one family cannot be reached through another family's address.
    [$other, $otherOwner] = treeFamily();
    $this->actingAs($otherOwner)->get(route('families.photos.show', [$other, $photo]))->assertNotFound();
    $this->get(route('families.photos.file', [$other, $photo, 'display']))->assertNotFound();
});

test('a restricted photo is hidden from roles that may not see restricted records', function () {
    [$family, $owner] = treeFamily();
    $editor = memberWithRole($family, FamilyRole::Editor);
    $contributor = memberWithRole($family, FamilyRole::Contributor);
    $viewer = memberWithRole($family, FamilyRole::Viewer);

    $open = uploadPhoto($this, $family, $owner, jpeg(400, 300));
    $restricted = uploadPhoto($this, $family, $owner, jpeg(500, 300), ['visibility' => 'restricted']);
    $private = uploadPhoto($this, $family, $owner, jpeg(600, 300), ['visibility' => 'private']);

    $sees = fn (User $user) => $this->actingAs($user)->get(route('families.photos.index', $family))->inertiaProps('photos.data');

    expect($sees($owner))->toHaveCount(3)
        ->and($sees($editor))->toHaveCount(2)
        ->and($sees($viewer))->toHaveCount(1)
        ->and($sees($viewer)[0]['id'])->toBe($open->id);

    $this->actingAs($contributor)->get(route('families.photos.show', [$family, $restricted]))->assertNotFound();
    $this->get(route('families.photos.file', [$family, $restricted, 'display']))->assertNotFound();
    $this->actingAs($editor)->get(route('families.photos.file', [$family, $restricted, 'display']))->assertOk();
    $this->get(route('families.photos.file', [$family, $private, 'display']))->assertNotFound();

    // Nobody can hide a file at a level they could not then see themselves.
    $mine = uploadPhoto($this, $family, $contributor, jpeg(700, 300), ['visibility' => 'private']);
    expect($mine->visibility->value)->toBe('family');
});

test('the uploader manages their own files; editors edit any; only administrators delete any', function () {
    [$family, $owner] = treeFamily();
    $admin = memberWithRole($family, FamilyRole::Admin);
    $editor = memberWithRole($family, FamilyRole::Editor);
    $contributor = memberWithRole($family, FamilyRole::Contributor);
    $other = memberWithRole($family, FamilyRole::Contributor);

    $photo = uploadPhoto($this, $family, $contributor);

    $this->actingAs($contributor)->patch(route('families.photos.update', [$family, $photo]), ['caption' => 'Hari Raya 1998', 'taken' => ['year' => 1998]])->assertSessionHasNoErrors();
    $this->actingAs($other)->patch(route('families.photos.update', [$family, $photo]), ['caption' => 'Changed'])->assertForbidden();
    $this->actingAs($editor)->patch(route('families.photos.update', [$family, $photo]), ['description' => 'At grandmother\'s house'])->assertSessionHasNoErrors();

    $photo->refresh();

    expect($photo->caption)->toBe('Hari Raya 1998')
        ->and($photo->description)->toBe('At grandmother\'s house')
        ->and($photo->taken->yearValue())->toBe(1998)
        ->and(DB::table('audit_logs')->where('action', 'photo.updated')->count())->toBe(2);

    $this->actingAs($other)->delete(route('families.photos.destroy', [$family, $photo]))->assertForbidden();
    $this->actingAs($editor)->delete(route('families.photos.destroy', [$family, $photo]))->assertForbidden();
    $this->actingAs($admin)->delete(route('families.photos.destroy', [$family, $photo]))->assertRedirect();

    expect(FamilyPhoto::query()->count())->toBe(0);

    // Their own, without anyone's help.
    $second = uploadPhoto($this, $family, $contributor, jpeg(320, 200));
    $this->actingAs($contributor)->delete(route('families.photos.destroy', [$family, $second]))->assertRedirect();
    expect(FamilyPhoto::query()->count())->toBe(0);
});

// --- Albums, people, cover -------------------------------------------------------------

test('albums group photos, and removing an album keeps its photos', function () {
    [$family, $owner] = treeFamily();
    $contributor = memberWithRole($family, FamilyRole::Contributor);
    $viewer = memberWithRole($family, FamilyRole::Viewer);

    $this->actingAs($viewer)->post(route('families.albums.store', $family), ['title' => 'Nope'])->assertForbidden();
    $this->actingAs($contributor)->post(route('families.albums.store', $family), ['title' => 'Reunion 2019'])->assertRedirect();

    $album = PhotoAlbum::query()->sole();
    $inAlbum = uploadPhoto($this, $family, $contributor, jpeg(400, 300), ['album_id' => $album->id]);
    $loose = uploadPhoto($this, $family, $contributor, jpeg(500, 300));

    $this->actingAs($viewer)->get(route('families.photos.index', ['family' => $family, 'album' => $album->id]))->assertInertia(fn (AssertableInertia $page) => $page
        ->has('photos.data', 1)
        ->where('photos.data.0.id', $inAlbum->id)
        ->where('albums.0.title', 'Reunion 2019')
        ->where('albums.0.count', 1)
        ->where('albums.0.can.update', false));

    // An album of another family cannot be used.
    [$other] = treeFamily();
    $foreign = new PhotoAlbum;
    $foreign->forceFill(['family_id' => $other->id, 'title' => 'Theirs'])->save();
    $this->actingAs($contributor)->patch(route('families.photos.update', [$family, $loose]), ['album_id' => $foreign->id])->assertSessionHasErrors('album_id');

    $this->actingAs($owner)->patch(route('families.albums.update', [$family, $album]), ['title' => 'Reunion', 'cover_photo_id' => $inAlbum->id])->assertSessionHasNoErrors();
    $this->actingAs($viewer)->patch(route('families.albums.update', [$family, $album]), ['title' => 'Nope'])->assertForbidden();
    expect($album->refresh()->title)->toBe('Reunion')->and($album->cover_photo_id)->toBe($inAlbum->id);

    $this->actingAs($contributor)->delete(route('families.albums.destroy', [$family, $album]))->assertRedirect();

    expect(PhotoAlbum::query()->where('family_id', $family->id)->count())->toBe(0)
        ->and($inAlbum->refresh()->album_id)->toBeNull()
        ->and(FamilyPhoto::query()->count())->toBe(2);
});

test('people are tagged in a photo, told about it, and can use it as their profile picture', function () {
    [$family, $owner] = treeFamily();
    $editor = memberWithRole($family, FamilyRole::Editor);
    $cousin = memberWithRole($family, FamilyRole::Viewer);
    $siti = person($family, 'Siti');
    $ahmad = person($family, 'Ahmad');
    $secret = person($family, 'Secret', ['visibility' => 'private']);
    $cousin->memberships()->sole()->forceFill(['linked_member_id' => $siti->id])->save();

    $photo = uploadPhoto($this, $family, $owner, null, ['member_ids' => [$ahmad->id, $secret->id]]);
    expect($photo->people()->count())->toBe(2);

    // The editor cannot see the private person: they can neither add nor accidentally remove them.
    $this->actingAs($editor)->patch(route('families.photos.update', [$family, $photo]), ['member_ids' => [$siti->id]])->assertSessionHasNoErrors();
    expect($photo->people()->pluck('family_members.id')->all())->toEqualCanonicalizing([$siti->id, $secret->id]);

    Notification::assertSentTo($cousin, TaggedInPhoto::class);

    $this->get(route('families.photos.show', [$family, $photo]))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('photos/show')
        ->has('photo.people', 1)
        ->where('photo.people.0.name', 'Siti'));

    $this->get(route('families.photos.index', ['family' => $family, 'person' => $siti->id]))->assertInertia(fn (AssertableInertia $page) => $page->has('photos.data', 1));
    $this->get(route('families.photos.index', ['family' => $family, 'person' => $ahmad->id]))->assertInertia(fn (AssertableInertia $page) => $page->has('photos.data', 0));

    // Profile picture: only of someone in the photo, only by someone who may edit that person.
    $this->post(route('families.photos.profile', [$family, $photo]), ['member_id' => $ahmad->id])->assertSessionHasErrors('member_id');
    $this->actingAs($cousin)->post(route('families.photos.profile', [$family, $photo]), ['member_id' => $siti->id])->assertForbidden();
    $this->actingAs($editor)->post(route('families.photos.profile', [$family, $photo]), ['member_id' => $siti->id])->assertSessionHasNoErrors();

    expect($siti->refresh()->profile_photo_id)->toBe($photo->id);

    $this->actingAs($cousin)->get(route('families.members.show', [$family, $siti]))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('member.photo_url', route('families.photos.file', [$family, $photo, 'thumb']))
        ->has('photos', 1));

    // A profile picture the viewer may not see is simply not shown.
    $photo->forceFill(['visibility' => 'restricted'])->save();
    app()->forgetScopedInstances();   // what a new request starts with
    $this->get(route('families.members.show', [$family, $siti]))->assertInertia(fn (AssertableInertia $page) => $page->where('member.photo_url', null)->has('photos', 0));

    // Untagged: no longer their picture.
    $this->actingAs($editor)->patch(route('families.photos.update', [$family, $photo]), ['member_ids' => []])->assertSessionHasNoErrors();
    expect($siti->refresh()->profile_photo_id)->toBeNull()->and($photo->people()->pluck('family_members.id')->all())->toBe([$secret->id]);
});

test('the cover image goes through the same checks and is one of the family\'s photos', function () {
    [$family, $owner] = treeFamily();
    $viewer = memberWithRole($family, FamilyRole::Viewer);
    $editor = memberWithRole($family, FamilyRole::Editor);

    $this->actingAs($editor)->post(route('families.cover.store', $family), ['cover' => jpeg(1200, 600)])->assertForbidden();
    $this->actingAs($owner)->post(route('families.cover.store', $family), ['cover' => UploadedFile::fake()->createWithContent('cover.jpg', '<?php echo 1;')])->assertSessionHasErrors('cover');
    $this->get(route('families.cover.show', $family))->assertNotFound();

    $this->post(route('families.cover.store', $family), ['cover' => jpeg(1200, 600, '../../evil.php.jpg')])->assertSessionHasNoErrors();

    $cover = FamilyPhoto::query()->sole();
    expect($family->refresh()->cover_photo_id)->toBe($cover->id)->and($cover->storage_key)->not->toContain('evil');

    $this->actingAs($viewer)->get(route('families.cover.show', $family))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->get(route('families.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('families.0.cover_url', fn (?string $url) => $url !== null));

    // Any photo the whole family can see can be chosen instead.
    $other = uploadPhoto($this, $family, $owner, jpeg(1000, 500));
    $hidden = uploadPhoto($this, $family, $owner, jpeg(1000, 400), ['visibility' => 'restricted']);

    $this->actingAs($editor)->post(route('families.photos.cover', [$family, $other]))->assertForbidden();
    $this->actingAs($owner)->post(route('families.photos.cover', [$family, $hidden]))->assertSessionHasErrors('cover');
    $this->post(route('families.photos.cover', [$family, $other]))->assertSessionHasNoErrors();
    expect($family->refresh()->cover_photo_id)->toBe($other->id);

    $this->delete(route('families.cover.destroy', $family))->assertRedirect();
    expect($family->refresh()->cover_photo_id)->toBeNull()->and(FamilyPhoto::query()->count())->toBe(3);
    $this->get(route('families.cover.show', $family))->assertNotFound();
});

// --- Documents -------------------------------------------------------------------------

test('a PDF is read as page images; the file itself is only ever a download', function () {
    [$family, $owner] = treeFamily();
    $viewer = memberWithRole($family, FamilyRole::Viewer);
    $ahmad = person($family, 'Ahmad');

    $document = uploadDocument($this, $family, $owner, null, ['category' => 'birth_certificate', 'member_ids' => [$ahmad->id]]);
    $folder = "families/{$family->id}/documents/{$document->id}";

    expect($document->status)->toBe(MediaStatus::Ready)
        ->and($document->title)->toBe('Birth certificate')
        ->and($document->page_count)->toBe(2)
        ->and($document->storage_key)->toBe("{$folder}/original.pdf")
        ->and($document->pageKeys())->toBe(["{$folder}/page-001.jpg", "{$folder}/page-002.jpg"])
        ->and($document->preview_key)->toBe("{$folder}/page-001.jpg");

    Storage::disk('local')->assertMissing("{$folder}/quarantine");

    $this->actingAs($viewer)->get(route('families.documents.index', ['family' => $family, 'category' => 'birth_certificate']))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('documents/index')
        ->has('documents.data', 1)
        ->where('documents.data.0.category_label', 'Birth certificate')
        ->where('documents.data.0.page_count', 2));
    $this->get(route('families.documents.index', ['family' => $family, 'category' => 'letter']))->assertInertia(fn (AssertableInertia $page) => $page->has('documents.data', 0));

    $this->get(route('families.documents.show', [$family, $document]))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('documents/show')
        ->has('document.urls.pages', 2)
        ->where('document.people.0.name', 'Ahmad'));

    $this->get(route('families.documents.page', [$family, $document, 2]))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->get(route('families.documents.page', [$family, $document, 3]))->assertNotFound();
    $this->get(route('families.documents.thumb', [$family, $document]))->assertOk();

    $download = $this->get(route('families.documents.download', [$family, $document]))->assertOk();

    expect($download->headers->get('Content-Disposition'))->toStartWith('attachment;')->toContain('Birth certificate.pdf')
        ->and($download->headers->get('Content-Security-Policy'))->toContain('sandbox')
        ->and($download->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and(DB::table('audit_logs')->where('action', 'document.downloaded')->where('user_id', $viewer->id)->count())->toBe(1);

    $this->get(route('families.members.show', [$family, $ahmad]))->assertInertia(fn (AssertableInertia $page) => $page->has('documents', 1));
});

test('a document can be made view-only for viewers and contributors', function () {
    [$family, $owner] = treeFamily();
    $editor = memberWithRole($family, FamilyRole::Editor);
    $contributor = memberWithRole($family, FamilyRole::Contributor);
    $viewer = memberWithRole($family, FamilyRole::Viewer);

    $document = uploadDocument($this, $family, $owner, null, ['downloadable' => false]);
    $mine = uploadDocument($this, $family, $contributor, sampleFile('photo-with-gps.jpg', 'Land grant scan.jpg'));

    expect($mine->status)->toBe(MediaStatus::Ready)->and($mine->mime_type)->toBe('image/jpeg')->and($mine->page_count)->toBe(1);

    $this->actingAs($viewer)->get(route('families.documents.page', [$family, $document, 1]))->assertOk();
    $this->get(route('families.documents.download', [$family, $document]))->assertForbidden();
    $this->get(route('families.documents.show', [$family, $document]))->assertInertia(fn (AssertableInertia $page) => $page->where('document.urls.download', null));
    $this->actingAs($contributor)->get(route('families.documents.download', [$family, $document]))->assertForbidden();
    $this->actingAs($editor)->get(route('families.documents.download', [$family, $document]))->assertOk();

    // Only someone who may edit the document can change that.
    $this->actingAs($contributor)->patch(route('families.documents.update', [$family, $document]), ['downloadable' => true])->assertForbidden();
    $this->actingAs($contributor)->patch(route('families.documents.update', [$family, $mine]), ['title' => 'Land grant, 1952', 'category' => 'land_record', 'issued' => ['year' => 1952]])->assertSessionHasNoErrors();
    $this->actingAs($editor)->patch(route('families.documents.update', [$family, $document]), ['downloadable' => true, 'visibility' => 'restricted'])->assertSessionHasNoErrors();

    expect($mine->refresh()->title)->toBe('Land grant, 1952')
        ->and($mine->category->value)->toBe('land_record')
        ->and($mine->issued->yearValue())->toBe(1952)
        ->and($document->refresh()->downloadable)->toBeTrue();

    // Now restricted: gone for the viewer, page images included.
    $this->actingAs($viewer)->get(route('families.documents.page', [$family, $document, 1]))->assertNotFound();
    $this->get(route('families.documents.download', [$family, $document]))->assertNotFound();
    $this->get(route('families.documents.index', $family))->assertInertia(fn (AssertableInertia $page) => $page->has('documents.data', 1));
});

test('a damaged PDF is refused', function () {
    [$family, $owner] = treeFamily();

    $broken = UploadedFile::fake()->createWithContent('will.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\nthis is not a real document");
    $document = uploadDocument($this, $family, $owner, $broken);

    expect($document->status)->toBe(MediaStatus::Rejected)
        ->and($document->rejection_reason)->toContain('could not be opened')
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and($family->refresh()->storage_bytes_used)->toBe(0);

    Notification::assertSentTo($owner, UploadRejected::class);
});

// --- Timeline --------------------------------------------------------------------------

test('photos and documents are attached to timeline entries within what each person may see', function () {
    [$family, $owner] = treeFamily();
    $contributor = memberWithRole($family, FamilyRole::Contributor);
    $viewer = memberWithRole($family, FamilyRole::Viewer);

    $this->actingAs($owner)->post(route('families.timeline.store', $family), ['title' => 'Moved to Kota Kinabalu', 'category' => 'migration', 'event' => ['year' => 1960]]);
    $entry = TimelineEntry::query()->sole();

    $photo = uploadPhoto($this, $family, $owner);
    $hidden = uploadPhoto($this, $family, $owner, jpeg(300, 200), ['visibility' => 'restricted']);
    $document = uploadDocument($this, $family, $owner);

    $attach = fn (array $data) => $this->post(route('families.timeline.attachments.store', [$family, $entry]), $data);

    $this->actingAs($contributor);
    $attach(['photo_id' => $photo->id])->assertForbidden();

    $this->actingAs($owner);
    $attach(['photo_id' => $photo->id])->assertSessionHasNoErrors();
    $attach(['photo_id' => $photo->id])->assertSessionHasNoErrors();   // twice is once
    $attach(['photo_id' => $hidden->id])->assertSessionHasNoErrors();
    $attach(['document_id' => $document->id])->assertSessionHasNoErrors();
    $attach(['photo_id' => $photo->id, 'document_id' => $document->id])->assertSessionHasErrors();
    $attach([])->assertSessionHasErrors();

    // Another family's photo "does not exist".
    [$other, $otherOwner] = treeFamily();
    $theirs = uploadPhoto($this, $other, $otherOwner, jpeg(310, 210));
    $this->actingAs($owner);
    $attach(['photo_id' => $theirs->id])->assertSessionHasErrors('photo_id');

    expect(DB::table('timeline_attachments')->count())->toBe(3);

    $attachments = fn (User $user) => collect($this->actingAs($user)->get(route('families.timeline.index', $family))->inertiaProps('items'))
        ->firstWhere('id', $entry->id)['attachments'];

    expect($attachments($owner))->toHaveCount(3)
        ->and($attachments($viewer))->toHaveCount(2)
        ->and(collect($attachments($viewer))->pluck('file_id')->all())->toEqualCanonicalizing([$photo->id, $document->id]);

    $id = DB::table('timeline_attachments')->where('photo_id', $photo->id)->value('id');
    $this->actingAs($viewer)->delete(route('families.timeline.attachments.destroy', [$family, $entry, $id]))->assertForbidden();
    $this->actingAs($owner)->delete(route('families.timeline.attachments.destroy', [$family, $entry, $id]))->assertRedirect();

    // Deleting a file takes its attachment with it once erased; meanwhile it is simply not listed.
    $this->delete(route('families.documents.destroy', [$family, $document]));
    expect($attachments($owner))->toHaveCount(1);
});

test('the uploader on the page sends one file at a time and gets a plain answer', function () {
    [$family, $owner] = treeFamily();
    [, $outsider] = treeFamily();
    $viewer = memberWithRole($family, FamilyRole::Viewer);
    $json = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];

    $this->actingAs($owner)->post(route('families.photos.store', $family), ['files' => [jpeg(700, 500)], 'visibility' => 'family'], $json)
        ->assertOk()
        ->assertExactJson(['stored' => 1, 'problems' => []]);

    // Refused by content: the reason comes back, with 422 when nothing was stored.
    $this->post(route('families.photos.store', $family), ['files' => [UploadedFile::fake()->createWithContent('x.jpg', '<?php echo 1;')]], $json)
        ->assertStatus(422)
        ->assertJsonPath('stored', 0)
        ->assertJsonCount(1, 'problems');

    $this->post(route('families.documents.store', $family), ['files' => [sampleFile('two-pages.pdf')], 'category' => 'other', 'visibility' => 'family'], $json)
        ->assertOk()
        ->assertJsonPath('stored', 1);

    // Invalid input and permissions answer as before, in JSON.
    $this->post(route('families.photos.store', $family), ['files' => []], $json)->assertStatus(422)->assertJsonValidationErrors('files');
    $this->actingAs($viewer)->post(route('families.photos.store', $family), ['files' => [jpeg(300, 300)]], $json)->assertForbidden();
    $this->actingAs($outsider)->post(route('families.photos.store', $family), ['files' => [jpeg(310, 300)]], $json)->assertNotFound();

    // No flash message is left behind for the next page: the page shows its own summary.
    expect(session(SessionKey::FLASH_DATA))->toBeNull()
        ->and(FamilyPhoto::query()->count())->toBe(1)
        ->and(FamilyDocument::query()->count())->toBe(1);
});

test('a photo made smaller on the device keeps the date it was taken', function () {
    [$family, $owner] = treeFamily();

    // What the browser sends after shrinking: a JPEG whose only EXIF is DateTimeOriginal
    // (written by resources/js/lib/image-shrink.ts, withTakenDate).
    $image = UploadedFile::fake()->image('shrunk.jpg', 400, 300);
    $bytes = (string) file_get_contents($image->getRealPath());
    $tiff = 'MM'.pack('n', 42).pack('N', 8)
        .pack('n', 1).pack('n', 0x8769).pack('n', 4).pack('N', 1).pack('N', 26).pack('N', 0)
        .pack('n', 1).pack('n', 0x9003).pack('n', 2).pack('N', 20).pack('N', 44).pack('N', 0)
        ."2019:08:31 09:15:00\0";
    $segment = "\xFF\xE1".pack('n', 2 + 6 + strlen($tiff))."Exif\0\0".$tiff;
    file_put_contents($image->getRealPath(), substr($bytes, 0, 2).$segment.substr($bytes, 2));

    $photo = uploadPhoto($this, $family, $owner, $image);

    expect($photo->refresh()->status->value)->toBe('ready')
        ->and($photo->taken->label())->toBe('31 Aug 2019');
});
