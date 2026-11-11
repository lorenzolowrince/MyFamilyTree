<?php

use App\Enums\FamilyPermission;
use App\Enums\FamilyRole;
use App\Enums\MembershipStatus;
use App\Models\Family;
use App\Models\User;
use App\Services\Authorization\FamilyAccess;
use Database\Factories\FamilyFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

/** A family with one member in the given role. */
function familyWith(FamilyRole $role): array
{
    $family = Family::factory()->create();

    if ($role === FamilyRole::Owner) {
        return [$family, $family->owner];
    }

    $user = User::factory()->create();
    FamilyFactory::addMember($family, $user, $role);

    return [$family, $user];
}

// --- Seeded matrix ---------------------------------------------------------------------

test('the five roles are seeded with their ranks', function () {
    $roles = DB::table('family_roles')->orderByDesc('rank')->pluck('rank', 'key')->all();

    expect($roles)->toBe([
        'owner' => 50,
        'admin' => 40,
        'editor' => 30,
        'contributor' => 20,
        'viewer' => 10,
    ]);
});

test('every permission is seeded and granted to at least one role', function () {
    expect(DB::table('family_permissions')->count())->toBe(count(FamilyPermission::cases()));

    foreach (FamilyPermission::cases() as $permission) {
        expect(DB::table('family_role_permissions')->where('permission_id', $permission->id())->exists())
            ->toBeTrue("{$permission->value} is granted to nobody");
    }
});

test('running the seeder again changes nothing', function () {
    $before = DB::table('family_role_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->all();

    $this->seed(RolesAndPermissionsSeeder::class);

    expect(DB::table('family_role_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->all())->toEqual($before);
});

/*
 * One row per line of the matrix in docs/architecture/05-roles-permissions.md:
 * permission => [owner, admin, editor, contributor, viewer].
 */
dataset('matrix', [
    'view tree' => [FamilyPermission::TreeView, [true, true, true, true, true]],
    'living sensitive info' => [FamilyPermission::MembersViewLivingSensitive, [true, true, true, false, false]],
    'restricted records' => [FamilyPermission::MembersViewRestricted, [true, true, true, false, false]],
    'private records' => [FamilyPermission::MembersViewPrivate, [true, true, false, false, false]],
    'add members directly' => [FamilyPermission::MembersCreate, [true, true, true, false, false]],
    'edit members directly' => [FamilyPermission::MembersUpdate, [true, true, true, false, false]],
    'delete or merge members' => [FamilyPermission::MembersDelete, [true, true, false, false, false]],
    'manage relationships' => [FamilyPermission::RelationshipsManage, [true, true, true, false, false]],
    'add timeline entries' => [FamilyPermission::TimelineCreate, [true, true, true, false, false]],
    'view media' => [FamilyPermission::MediaView, [true, true, true, true, true]],
    'upload media' => [FamilyPermission::MediaUpload, [true, true, true, true, false]],
    'edit any media' => [FamilyPermission::MediaEditAny, [true, true, true, false, false]],
    'delete any media' => [FamilyPermission::MediaDeleteAny, [true, true, false, false, false]],
    'download any document' => [FamilyPermission::DocumentsDownloadAny, [true, true, true, false, false]],
    'propose changes' => [FamilyPermission::ChangesPropose, [false, false, true, true, false]],
    'review changes' => [FamilyPermission::ChangesReview, [true, true, true, false, false]],
    'invite people' => [FamilyPermission::MembersInvite, [true, true, false, false, false]],
    'assign roles' => [FamilyPermission::RolesAssign, [true, true, false, false, false]],
    'remove people' => [FamilyPermission::MembersRemove, [true, true, false, false, false]],
    'generate reports' => [FamilyPermission::ReportsGenerate, [true, true, true, true, false]],
    'restricted data in reports' => [FamilyPermission::ReportsIncludeRestricted, [true, true, true, false, false]],
    'GEDCOM export' => [FamilyPermission::GedcomExport, [true, true, false, false, false]],
    'GEDCOM import' => [FamilyPermission::GedcomImport, [true, true, false, false, false]],
    'full audit history' => [FamilyPermission::AuditView, [true, true, false, false, false]],
    'record history' => [FamilyPermission::AuditViewRecords, [true, true, true, false, false]],
    'family settings' => [FamilyPermission::FamilyUpdate, [true, true, false, false, false]],
    'grant support access' => [FamilyPermission::FamilyGrantSupportAccess, [true, false, false, false, false]],
    'transfer ownership' => [FamilyPermission::FamilyTransferOwnership, [true, false, false, false, false]],
    'delete family' => [FamilyPermission::FamilyDelete, [true, false, false, false, false]],
]);

test('the seeded matrix matches the documented matrix', function (FamilyPermission $permission, array $expected) {
    $access = app(FamilyAccess::class);
    $roles = [FamilyRole::Owner, FamilyRole::Admin, FamilyRole::Editor, FamilyRole::Contributor, FamilyRole::Viewer];

    foreach ($roles as $index => $role) {
        expect($access->roleHas($role, $permission))
            ->toBe($expected[$index], "{$role->value} / {$permission->value}");
    }
})->with('matrix');

test('the matrix test covers every permission', function () {
    $source = file_get_contents(__FILE__);

    foreach (FamilyPermission::cases() as $permission) {
        expect($source)->toContain("FamilyPermission::{$permission->name},");
    }
});

// --- Membership ------------------------------------------------------------------------

test('a family gets its owner as the Owner member', function () {
    $family = Family::factory()->create();

    expect(app(FamilyAccess::class)->role($family->owner, $family))->toBe(FamilyRole::Owner)
        ->and($family->storage_quota_bytes)->toBe(10 * 1024 ** 3);
});

test('someone outside the family has no role and no permissions', function () {
    $family = Family::factory()->create();
    $stranger = User::factory()->create();

    $access = app(FamilyAccess::class);

    expect($access->isMember($stranger, $family))->toBeFalse()
        ->and($access->can($stranger, $family, FamilyPermission::TreeView))->toBeFalse();
});

test('a role in one family gives nothing in another family', function () {
    [$family, $admin] = familyWith(FamilyRole::Admin);
    $otherFamily = Family::factory()->create();

    $access = app(FamilyAccess::class);

    expect($access->can($admin, $family, FamilyPermission::FamilyUpdate))->toBeTrue()
        ->and($access->can($admin, $otherFamily, FamilyPermission::TreeView))->toBeFalse();
});

test('a suspended membership grants nothing', function () {
    $family = Family::factory()->create();
    $user = User::factory()->create();
    FamilyFactory::addMember($family, $user, FamilyRole::Editor, MembershipStatus::Suspended);

    expect(app(FamilyAccess::class)->can($user, $family, FamilyPermission::TreeView))->toBeFalse();
});

test('a suspended or deactivated account loses its family access', function (string $state) {
    $family = Family::factory()->create();
    $user = User::factory()->{$state}()->create();
    FamilyFactory::addMember($family, $user, FamilyRole::Admin);

    expect(app(FamilyAccess::class)->can($user, $family, FamilyPermission::TreeView))->toBeFalse();
})->with(['suspended', 'deactivated']);

test('the same person can hold different roles in different families', function () {
    $user = User::factory()->create();
    $own = Family::factory()->create(['owner_id' => $user->id]);
    $inLaws = Family::factory()->create();
    FamilyFactory::addMember($inLaws, $user, FamilyRole::Viewer);

    $access = app(FamilyAccess::class);

    expect($access->role($user, $own))->toBe(FamilyRole::Owner)
        ->and($access->role($user, $inLaws))->toBe(FamilyRole::Viewer)
        ->and($user->families()->count())->toBe(2);
});

// --- Database guarantees ---------------------------------------------------------------

test('the database refuses a second membership for the same user and family', function () {
    $family = Family::factory()->create();
    $user = User::factory()->create();

    FamilyFactory::addMember($family, $user, FamilyRole::Viewer);
    FamilyFactory::addMember($family, $user, FamilyRole::Editor);
})->throws(UniqueConstraintViolationException::class);

test('the database refuses a second owner for a family', function () {
    $family = Family::factory()->create();

    FamilyFactory::addMember($family, User::factory()->create(), FamilyRole::Owner);
})->throws(UniqueConstraintViolationException::class);

test('the database refuses an unknown membership status', function () {
    $family = Family::factory()->create();

    DB::table('family_user')->where('family_id', $family->id)->update(['status' => 'banned']);
})->throws(QueryException::class);

test('the database refuses an unknown account status', function () {
    $user = User::factory()->create();

    DB::table('users')->where('id', $user->id)->update(['status' => 'vip']);
})->throws(QueryException::class);

test('a user who owns a family cannot be hard-deleted from the database', function () {
    $family = Family::factory()->create();

    DB::table('users')->where('id', $family->owner_id)->delete();
})->throws(QueryException::class);

// --- FamilyPolicy ----------------------------------------------------------------------

test('creating a family needs a verified email address', function () {
    expect(User::factory()->create()->can('create', Family::class))->toBeTrue()
        ->and(User::factory()->unverified()->create()->can('create', Family::class))->toBeFalse()
        ->and(User::factory()->suspended()->create()->can('create', Family::class))->toBeFalse();
});

test('people outside a family get "not found", not "forbidden"', function (string $ability) {
    $family = Family::factory()->create();
    $stranger = User::factory()->create();

    $response = Gate::forUser($stranger)->inspect($ability, $family);

    expect($response->denied())->toBeTrue()
        ->and($response->status())->toBe(404);
})->with(['view', 'update', 'delete', 'transferOwnership', 'grantSupportAccess', 'invite', 'viewAudit']);

test('super administrators cannot see into a family they do not belong to', function () {
    $family = Family::factory()->create();
    $superAdmin = User::factory()->superAdmin()->create();

    $response = Gate::forUser($superAdmin)->inspect('view', $family);

    expect($response->denied())->toBeTrue()
        ->and($response->status())->toBe(404);
});

test('family policy follows the role', function (FamilyRole $role, array $allowed) {
    [$family, $user] = familyWith($role);

    foreach (['view', 'update', 'invite', 'viewAudit', 'transferOwnership', 'grantSupportAccess', 'delete'] as $ability) {
        expect($user->can($ability, $family))->toBe(in_array($ability, $allowed, true), "{$role->value} / {$ability}");
    }
})->with([
    'owner' => [FamilyRole::Owner, ['view', 'update', 'invite', 'viewAudit', 'transferOwnership', 'grantSupportAccess', 'delete']],
    'admin' => [FamilyRole::Admin, ['view', 'update', 'invite', 'viewAudit']],
    'editor' => [FamilyRole::Editor, ['view']],
    'contributor' => [FamilyRole::Contributor, ['view']],
    'viewer' => [FamilyRole::Viewer, ['view']],
]);

test('a member who lacks a permission gets "forbidden" (they already know the family exists)', function () {
    [$family, $viewer] = familyWith(FamilyRole::Viewer);

    $response = Gate::forUser($viewer)->inspect('update', $family);

    expect($response->denied())->toBeTrue()
        ->and($response->status())->toBeNull();
});

test('nobody can grant a role equal to or higher than their own', function () {
    [$family, $admin] = familyWith(FamilyRole::Admin);
    $owner = $family->owner;
    $editor = User::factory()->create();
    FamilyFactory::addMember($family, $editor, FamilyRole::Editor);

    // Owner: anything below Owner.
    expect($owner->can('assignRole', [$family, FamilyRole::Admin]))->toBeTrue()
        ->and($owner->can('assignRole', [$family, FamilyRole::Owner]))->toBeFalse()
        // Admin: up to Editor.
        ->and($admin->can('assignRole', [$family, FamilyRole::Editor]))->toBeTrue()
        ->and($admin->can('assignRole', [$family, FamilyRole::Viewer]))->toBeTrue()
        ->and($admin->can('assignRole', [$family, FamilyRole::Admin]))->toBeFalse()
        ->and($admin->can('assignRole', [$family, FamilyRole::Owner]))->toBeFalse()
        // Editors and below cannot assign roles at all.
        ->and($editor->can('assignRole', [$family, FamilyRole::Viewer]))->toBeFalse();
});

// --- Two-factor requirement ------------------------------------------------------------

test('owners, family administrators and super administrators must use two-factor authentication', function () {
    [, $owner] = familyWith(FamilyRole::Owner);
    [, $admin] = familyWith(FamilyRole::Admin);
    [, $editor] = familyWith(FamilyRole::Editor);
    [, $viewer] = familyWith(FamilyRole::Viewer);

    expect($owner->mustUseTwoFactor())->toBeTrue()
        ->and($admin->mustUseTwoFactor())->toBeTrue()
        ->and(User::factory()->superAdmin()->create()->mustUseTwoFactor())->toBeTrue()
        ->and($editor->mustUseTwoFactor())->toBeFalse()
        ->and($viewer->mustUseTwoFactor())->toBeFalse()
        ->and(User::factory()->create()->mustUseTwoFactor())->toBeFalse();
});
