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

];
