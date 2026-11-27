<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers. X-Frame-Options and X-Content-Type-Options are
 * already set by nginx (docker/nginx/default.conf) for every response.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Set before the view renders so Vite tags (and React's refresh preamble) carry it.
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy($nonce));
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        // Private app: the public pages exist for Google's OAuth review, not for search results.
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        // Only meaningful over HTTPS; sending it from local/dev hosts would pin
        // browsers to HTTPS for localhost.
        if ($request->isSecure() && app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * Scripts run only from the app with this response's nonce. Styles allow
     * 'unsafe-inline' for the style attributes React sets; fonts come from Google Fonts.
     */
    private function contentSecurityPolicy(string $nonce): string
    {
        $dev = $this->viteDevServer();
        $devWs = $dev === null ? null : preg_replace('/^http/', 'ws', $dev);

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$nonce}'", $dev],
            'style-src' => ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com', $dev],
            'font-src' => ["'self'", 'https://fonts.gstatic.com'],
            'img-src' => ["'self'", 'data:'],
            'connect-src' => ["'self'", $dev, $devWs],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'none'"],
        ];

        return collect($directives)
            ->map(fn (array $sources, string $name) => $name.' '.implode(' ', array_filter($sources)))
            ->implode('; ');
    }

    /** The Vite dev server origin, only on a local machine while `npm run dev` runs. */
    private function viteDevServer(): ?string
    {
        $hot = public_path('hot');

        return app()->isLocal() && is_file($hot) ? rtrim(trim((string) file_get_contents($hot)), '/') : null;
    }
}
