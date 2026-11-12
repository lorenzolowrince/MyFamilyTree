<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** The installable app (docs/architecture/07-pwa.md). */
function publicFile(string $path): string
{
    $contents = file_get_contents(public_path($path));

    expect($contents)->not->toBeFalse();

    return (string) $contents;
}

test('the manifest makes the app installable', function () {
    $manifest = json_decode(publicFile('manifest.webmanifest'), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest)
        ->name->toBe('My Family Tree')
        ->display->toBe('standalone')
        ->start_url->toBe('/dashboard')
        ->scope->toBe('/');

    $sizes = collect($manifest['icons'])->map(fn (array $icon) => $icon['sizes'].' '.$icon['purpose'])->all();
    expect($sizes)->toContain('192x192 any', '512x512 any', '512x512 maskable');

    foreach ($manifest['icons'] as $icon) {
        [$width, $height] = getimagesize(public_path(ltrim($icon['src'], '/'))) ?: [0, 0];
        expect("{$width}x{$height}")->toBe($icon['sizes']);
    }

    [$width, $height] = getimagesize(public_path('apple-touch-icon.png')) ?: [0, 0];
    expect("{$width}x{$height}")->toBe('180x180');
});

test('every page links the manifest and the app icons', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false)
        ->assertSee('<link rel="apple-touch-icon" href="/apple-touch-icon.png">', false)
        ->assertSee('name="apple-mobile-web-app-title" content="Family Tree"', false);
});

test('the service worker caches only files without personal data', function () {
    $worker = publicFile('sw.js');

    // Only these are ever written to the cache: the static list and compiled assets.
    expect(substr_count($worker, 'cache.put('))->toBe(1)
        ->and($worker)->toContain("url.pathname.startsWith('/build/assets/')")
        ->and($worker)->toContain("if (request.method !== 'GET')")
        ->and($worker)->toContain('url.origin !== self.location.origin');

    // Pages come from the network; the offline page is the only fallback.
    expect($worker)->toMatch('/async function navigate\(event\) \{\s+try \{\s+const response =\s+\(await event.preloadResponse\) \|\| \(await fetch\(event.request\)\);/');

    preg_match("/const STATIC_FILES = \[(.*?)\];/s", $worker, $match);
    preg_match_all("/'([^']+)'/", $match[1] ?? '', $files);

    foreach (['/manifest.webmanifest', '/favicon.svg', '/icons/icon-192.png', '/icons/icon-512.png'] as $file) {
        expect($files[1])->toContain($file);
        expect(file_exists(public_path(ltrim($file, '/'))))->toBeTrue();
    }

    expect(file_exists(public_path('offline.html')))->toBeTrue();
});

test('the offline page runs no script and holds no personal data', function () {
    $page = publicFile('offline.html');

    expect($page)
        ->not->toContain('<script')
        ->toContain('You are offline')
        ->toContain('<a href="">Try again</a>');
});
