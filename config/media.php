<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | The private disk that holds photos and documents. Files are never public:
    | every request is checked against the family's permissions first.
    |
    */

    'disk' => env('MEDIA_DISK', env('FILESYSTEM_DISK', 's3')),

    /*
    |--------------------------------------------------------------------------
    | Upload limits
    |--------------------------------------------------------------------------
    |
    | Sizes in bytes. Types are decided by reading the file's content, never by
    | its name or by what the browser claims.
    |
    */

    'photos' => [
        'max_bytes' => (int) env('MEDIA_PHOTO_MAX_BYTES', 25 * 1024 * 1024),
        'types' => ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'],
    ],

    'documents' => [
        'max_bytes' => (int) env('MEDIA_DOCUMENT_MAX_BYTES', 50 * 1024 * 1024),
        'types' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'image/tiff'],
        // Pages of a PDF turned into preview images.
        'preview_pages' => (int) env('MEDIA_DOCUMENT_PREVIEW_PAGES', 10),
    ],

    'max_files_per_upload' => 20,

    // Longest edge, in pixels. Larger images are scaled down when re-encoded.
    'sizes' => ['original' => 4096, 'display' => 1600, 'thumb' => 400],

    // Largest image accepted, in megapixels (guards against decompression bombs).
    'max_megapixels' => (int) env('MEDIA_MAX_MEGAPIXELS', 100),

    /*
    |--------------------------------------------------------------------------
    | Virus scanning
    |--------------------------------------------------------------------------
    |
    | ClamAV daemon (clamd) reached over TCP. When scanning is switched on and
    | the daemon cannot be reached, files stay in quarantine and are retried;
    | they are never released unscanned.
    |
    */

    'scanner' => [
        'enabled' => (bool) env('CLAMAV_ENABLED', true),
        'host' => env('CLAMAV_HOST', 'clamav'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'timeout' => (int) env('CLAMAV_TIMEOUT', 60),
    ],

    // Days a deleted file is kept before it is erased for good and its space released.
    'purge_after_days' => (int) env('MEDIA_PURGE_AFTER_DAYS', 30),

];
