<?php

// Architecture rules enforced in CI (docs/architecture/04-application-structure.md).
// More rules arrive with the code they guard, e.g. "every family route authorises" in Phase 4.
// Admin routes are checked route by route in tests/Feature/Admin/AdminAreaTest.php.

arch('no debugging helpers are left in application code')
    ->expect(['dd', 'dump', 'ddd', 'ray', 'var_dump', 'print_r', 'die'])
    ->not->toBeUsed();

arch('application code follows the security preset')
    ->preset()
    ->security();

arch('controllers are named consistently')
    ->expect('App\Http\Controllers')
    ->toHaveSuffix('Controller');

arch('models extend Eloquent')
    ->expect('App\Models')
    ->toExtend('Illuminate\Database\Eloquent\Model');

arch('strict environment access: env() is only read inside config files')
    ->expect('env')
    ->not->toBeUsedIn('App');

arch('enums are enums')
    ->expect('App\Enums')
    ->toBeEnums();

arch('policies are named consistently')
    ->expect('App\Policies')
    ->toHaveSuffix('Policy');

arch('business actions do not depend on the HTTP layer, so the future API can reuse them')
    ->expect('App\Actions')
    ->not->toUse(['Illuminate\Http\Request', 'App\Http', 'Inertia\Inertia']);

arch('models and services do not depend on controllers')
    ->expect(['App\Models', 'App\Services', 'App\Policies'])
    ->not->toUse('App\Http');
