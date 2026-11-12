<?php

use App\Http\Controllers\Families\ActivityController;
use App\Http\Controllers\Families\AlbumController;
use App\Http\Controllers\Families\ChangeRequestController;
use App\Http\Controllers\Families\CoverController;
use App\Http\Controllers\Families\DocumentController;
use App\Http\Controllers\Families\EventController;
use App\Http\Controllers\Families\FamilyController;
use App\Http\Controllers\Families\GedcomController;
use App\Http\Controllers\Families\MemberController;
use App\Http\Controllers\Families\MemberNameController;
use App\Http\Controllers\Families\PeopleController;
use App\Http\Controllers\Families\PhotoController;
use App\Http\Controllers\Families\RelationshipController;
use App\Http\Controllers\Families\RelationshipFinderController;
use App\Http\Controllers\Families\RelativeController;
use App\Http\Controllers\Families\ReportController;
use App\Http\Controllers\Families\TimelineAttachmentController;
use App\Http\Controllers\Families\TimelineController;
use App\Http\Controllers\Families\TreeController;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RememberCurrentFamily;
use Illuminate\Support\Facades\Route;

/*
| Family trees. Every route below `families/{family}` is authorised by a Policy in its
| controller: people outside the family get 404, members without the permission get 403
| (tests/Feature/Families). `{member}` and `{relationship}` only resolve inside `{family}`.
*/

Route::middleware(['auth', 'verified', EnsureTwoFactorEnabled::class])->group(function () {
    Route::get('families', [FamilyController::class, 'index'])->name('families.index');
    Route::get('families/create', [FamilyController::class, 'create'])->name('families.create');
    Route::post('families', [FamilyController::class, 'store'])->middleware('throttle:10,60')->name('families.store');

    Route::prefix('families/{family}')
        ->name('families.')
        ->middleware(RememberCurrentFamily::class)
        ->scopeBindings()
        ->group(function () {
            Route::get('/', [FamilyController::class, 'show'])->name('show');
            Route::get('settings', [FamilyController::class, 'edit'])->name('edit');
            Route::patch('settings', [FamilyController::class, 'update'])->name('update');

            Route::get('cover', [CoverController::class, 'show'])->name('cover.show');
            Route::post('cover', [CoverController::class, 'store'])->middleware('throttle:20,60')->name('cover.store');
            Route::delete('cover', [CoverController::class, 'destroy'])->name('cover.destroy');

            Route::get('tree', [TreeController::class, 'show'])->name('tree');

            Route::get('members', [MemberController::class, 'index'])->name('members.index');
            Route::get('members/lookup', [MemberController::class, 'lookup'])->middleware('throttle:120,1')->name('members.lookup');
            Route::get('members/duplicates', [MemberController::class, 'duplicates'])->middleware('throttle:120,1')->name('members.duplicates');
            Route::post('members', [MemberController::class, 'store'])->name('members.store');
            Route::get('members/{member}', [MemberController::class, 'show'])->whereUuid('member')->name('members.show');
            Route::patch('members/{member}', [MemberController::class, 'update'])->whereUuid('member')->name('members.update');
            Route::delete('members/{member}', [MemberController::class, 'destroy'])->whereUuid('member')->name('members.destroy');
            Route::post('members/{member}/root', [MemberController::class, 'root'])->whereUuid('member')->name('members.root');
            Route::post('members/{member}/relatives', [RelativeController::class, 'store'])->whereUuid('member')->name('members.relatives.store');

            Route::post('members/{member}/names', [MemberNameController::class, 'store'])->whereUuid('member')->name('members.names.store');
            Route::delete('members/{member}/names/{name}', [MemberNameController::class, 'destroy'])->whereUuid(['member', 'name'])->name('members.names.destroy');

            Route::get('timeline', [TimelineController::class, 'index'])->name('timeline.index');
            Route::post('timeline', [TimelineController::class, 'store'])->name('timeline.store');
            Route::patch('timeline/{entry}', [TimelineController::class, 'update'])->whereUuid('entry')->name('timeline.update');
            Route::delete('timeline/{entry}', [TimelineController::class, 'destroy'])->whereUuid('entry')->name('timeline.destroy');

            Route::get('events', [EventController::class, 'index'])->name('events.index');
            Route::post('events', [EventController::class, 'store'])->name('events.store');
            Route::patch('events/{event}', [EventController::class, 'update'])->whereUuid('event')->name('events.update');
            Route::delete('events/{event}', [EventController::class, 'destroy'])->whereUuid('event')->name('events.destroy');
            Route::post('events/{event}/timeline', [EventController::class, 'convert'])->whereUuid('event')->name('events.convert');

            Route::post('timeline/{entry}/attachments', [TimelineAttachmentController::class, 'store'])->whereUuid('entry')->name('timeline.attachments.store');
            Route::delete('timeline/{entry}/attachments/{attachment}', [TimelineAttachmentController::class, 'destroy'])->whereUuid(['entry', 'attachment'])->name('timeline.attachments.destroy');

            // Photos and albums. Uploads are limited per person; files are checked before they appear.
            Route::get('photos', [PhotoController::class, 'index'])->name('photos.index');
            Route::post('photos', [PhotoController::class, 'store'])->middleware('throttle:60,10')->name('photos.store');
            Route::get('photos/{photo}', [PhotoController::class, 'show'])->whereUuid('photo')->name('photos.show');
            Route::patch('photos/{photo}', [PhotoController::class, 'update'])->whereUuid('photo')->name('photos.update');
            Route::delete('photos/{photo}', [PhotoController::class, 'destroy'])->whereUuid('photo')->name('photos.destroy');
            Route::get('photos/{photo}/{size}.jpg', [PhotoController::class, 'file'])->whereUuid('photo')->whereIn('size', ['thumb', 'display', 'original'])->name('photos.file');
            Route::post('photos/{photo}/profile', [PhotoController::class, 'profile'])->whereUuid('photo')->name('photos.profile');
            Route::post('photos/{photo}/cover', [PhotoController::class, 'cover'])->whereUuid('photo')->name('photos.cover');

            Route::post('albums', [AlbumController::class, 'store'])->name('albums.store');
            Route::patch('albums/{album}', [AlbumController::class, 'update'])->whereUuid('album')->name('albums.update');
            Route::delete('albums/{album}', [AlbumController::class, 'destroy'])->whereUuid('album')->name('albums.destroy');

            // Documents: read as page images; the file itself only ever as a download.
            Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
            Route::post('documents', [DocumentController::class, 'store'])->middleware('throttle:60,10')->name('documents.store');
            Route::get('documents/{document}', [DocumentController::class, 'show'])->whereUuid('document')->name('documents.show');
            Route::patch('documents/{document}', [DocumentController::class, 'update'])->whereUuid('document')->name('documents.update');
            Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->whereUuid('document')->name('documents.destroy');
            Route::get('documents/{document}/thumb.jpg', [DocumentController::class, 'thumb'])->whereUuid('document')->name('documents.thumb');
            Route::get('documents/{document}/pages/{page}.jpg', [DocumentController::class, 'page'])->whereUuid('document')->whereNumber('page')->name('documents.page');
            Route::get('documents/{document}/download', [DocumentController::class, 'download'])->whereUuid('document')->middleware('throttle:60,1')->name('documents.download');

            // People with access, invitations, roles.
            Route::get('people', [PeopleController::class, 'index'])->name('people.index');
            Route::post('people/invitations', [PeopleController::class, 'invite'])->middleware('throttle:30,60')->name('people.invite');
            Route::delete('people/invitations/{invitation}', [PeopleController::class, 'revokeInvitation'])->whereUuid('invitation')->name('people.invitations.destroy');
            Route::patch('people/{membership}/role', [PeopleController::class, 'updateRole'])->whereUuid('membership')->name('people.role');
            Route::patch('people/{membership}/link', [PeopleController::class, 'link'])->whereUuid('membership')->name('people.link');
            Route::post('people/{membership}/transfer', [PeopleController::class, 'transfer'])->whereUuid('membership')->middleware('throttle:6,1')->name('people.transfer');
            Route::delete('people/{membership}', [PeopleController::class, 'destroy'])->whereUuid('membership')->name('people.destroy');

            // Proposals waiting for approval.
            Route::get('changes', [ChangeRequestController::class, 'index'])->name('changes.index');
            Route::post('changes/{change}/approve', [ChangeRequestController::class, 'approve'])->whereUuid('change')->name('changes.approve');
            Route::post('changes/{change}/reject', [ChangeRequestController::class, 'reject'])->whereUuid('change')->name('changes.reject');
            Route::delete('changes/{change}', [ChangeRequestController::class, 'withdraw'])->whereUuid('change')->name('changes.withdraw');

            // History of changes.
            Route::get('activity', [ActivityController::class, 'index'])->name('activity.index');
            Route::get('activity/audit', [ActivityController::class, 'audit'])->name('activity.audit');

            // GEDCOM: export the tree, import a file (checked and previewed before anything changes).
            Route::get('gedcom', [GedcomController::class, 'index'])->name('gedcom.index');
            Route::get('gedcom/export', [GedcomController::class, 'export'])->middleware('throttle:10,60')->name('gedcom.export');
            Route::post('gedcom/imports', [GedcomController::class, 'store'])->middleware('throttle:10,60')->name('gedcom.store');
            Route::get('gedcom/imports/{import}', [GedcomController::class, 'show'])->whereUuid('import')->name('gedcom.show');
            Route::post('gedcom/imports/{import}/confirm', [GedcomController::class, 'confirm'])->whereUuid('import')->name('gedcom.confirm');
            Route::delete('gedcom/imports/{import}', [GedcomController::class, 'cancel'])->whereUuid('import')->name('gedcom.cancel');

            // PDF reports: made in the background, downloadable by the person who asked.
            Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
            Route::post('reports', [ReportController::class, 'store'])->middleware('throttle:10,60')->name('reports.store');
            Route::get('reports/{report}/download', [ReportController::class, 'download'])->whereUuid('report')->name('reports.download');
            Route::delete('reports/{report}', [ReportController::class, 'destroy'])->whereUuid('report')->name('reports.destroy');

            Route::get('relationship-finder', [RelationshipFinderController::class, 'show'])->middleware('throttle:60,1')->name('relationships.finder');

            Route::patch('relationships/{relationship}', [RelationshipController::class, 'update'])->whereUuid('relationship')->name('relationships.update');
            Route::delete('relationships/{relationship}', [RelationshipController::class, 'destroy'])->whereUuid('relationship')->name('relationships.destroy');
        });
});
