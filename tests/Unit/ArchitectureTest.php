<?php

// Architecture rules enforced in CI (docs/architecture/04-application-structure.md).
// More rules arrive with the code they guard, e.g. "every controller authorises" in Phase 3.

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
