<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default storage quota
    |--------------------------------------------------------------------------
    |
    | Photo and document storage each new family starts with, in bytes.
    | Default: 10 GB (docs/architecture/02-database.md, `families`).
    |
    */

    'default_storage_quota_bytes' => (int) env('FAMILY_DEFAULT_STORAGE_QUOTA_BYTES', 10 * 1024 ** 3),

    /*
    |--------------------------------------------------------------------------
    | Two-factor authentication for family Owners and Administrators
    |--------------------------------------------------------------------------
    |
    | Required everywhere except on a developer's own machine, where it would
    | get in the way of trying the app (docs/architecture/06-security.md).
    | Super Administrators must always use two-factor, whatever this says.
    |
    */

    'require_two_factor_for_admins' => (bool) env('FAMILY_ADMIN_TWO_FACTOR', env('APP_ENV', 'production') !== 'local'),

];
