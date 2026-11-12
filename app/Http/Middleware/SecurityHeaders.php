<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers (docs/architecture/06-security.md).
 *
 * Scripts only run when they carry this request's nonce, which Vite adds to the tags it
 * generates. An injected <script> has no nonce, so the browser refuses to run it.
 * HSTS is only sent over HTTPS so local HTTP development keeps working.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=(), usb=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        // A stricter policy set by the response itself (served files: nothing may run) is kept.
        if (! $this->isExempt($request) && ! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $this->contentSecurityPolicy($nonce));
        }

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
        }

        return $response;
    }

    private function contentSecurityPolicy(string $nonce): string
    {
        $script = ["'self'", "'nonce-{$nonce}'"];
        $style = ["'self'", "'unsafe-inline'"];
        $connect = ["'self'"];
        $font = ["'self'", 'data:'];
        $img = ["'self'", 'data:', 'blob:'];

        // The Vite dev server (hot reload) runs on another port; only while it is running.
        if (Vite::isRunningHot()) {
            $host = parse_url(Vite::asset(''), PHP_URL_HOST) ?: 'localhost';
            $port = parse_url(Vite::asset(''), PHP_URL_PORT);
            $origin = $host.($port ? ":{$port}" : '');

            $script[] = "http://{$origin}";
            $style[] = "http://{$origin}";
            $font[] = "http://{$origin}";
            $img[] = "http://{$origin}";
            $connect[] = "http://{$origin}";
            $connect[] = "ws://{$origin}";
        }

        return implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', $script),
            // Inline styles stay allowed: UI components position themselves with style attributes.
            // Styles cannot run code, so the script rule above is the one that stops XSS.
            'style-src '.implode(' ', $style),
            'img-src '.implode(' ', $img),
            'font-src '.implode(' ', $font),
            'connect-src '.implode(' ', $connect),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    /**
     * Horizon ships its own pre-built dashboard with inline scripts that cannot carry our
     * nonce. It is only reachable by Super Administrators.
     */
    private function isExempt(Request $request): bool
    {
        return $request->is(trim((string) config('horizon.path', 'horizon'), '/').'*');
    }
}
