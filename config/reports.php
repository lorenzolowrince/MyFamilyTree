<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PDF engine
    |--------------------------------------------------------------------------
    |
    | Reports are HTML printed to PDF by Chromium, running in a Gotenberg
    | container that only the application can reach.
    |
    */

    'gotenberg_url' => env('GOTENBERG_URL', 'http://gotenberg:3000'),

    'timeout' => (int) env('REPORTS_TIMEOUT', 120),

    // Days a generated report can be downloaded before its file is deleted.
    'keep_days' => (int) env('REPORTS_KEEP_DAYS', 7),

    // Most generations a report may cover, and the most a tree chart may draw.
    'max_generations' => 8,
    'max_chart_generations' => 5,

    // Most people listed in one report.
    'max_people' => 3000,

];
