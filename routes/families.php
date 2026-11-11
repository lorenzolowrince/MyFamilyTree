<?php

use App\Http\Controllers\Families\CoverController;
use App\Http\Controllers\Families\FamilyController;
use App\Http\Controllers\Families\MemberController;
use App\Http\Controllers\Families\RelationshipController;
use App\Http\Controllers\Families\RelativeController;
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

            Route::patch('relationships/{relationship}', [RelationshipController::class, 'update'])->whereUuid('relationship')->name('relationships.update');
            Route::delete('relationships/{relationship}', [RelationshipController::class, 'destroy'])->whereUuid('relationship')->name('relationships.destroy');
        });
});
