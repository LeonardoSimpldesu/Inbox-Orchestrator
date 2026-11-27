<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_responses_include_baseline_security_headers(): void
    {
        $response = $this->get('/');

        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
    }

    public function test_responses_ask_search_engines_not_to_index(): void
    {
        $this->get('/sobre')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_hsts_is_not_sent_outside_production(): void
    {
        $this->get('https://localhost/')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_not_sent_over_plain_http(): void
    {
        $this->app['env'] = 'production';

        $this->get('http://localhost/')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_sent_over_https_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->get('https://localhost/')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    private function csp(string $uri = '/sobre'): string
    {
        return (string) $this->get($uri)->headers->get('Content-Security-Policy');
    }

    public function test_content_security_policy_allows_only_the_apps_own_scripts(): void
    {
        $policy = $this->csp();
        $nonce = Vite::cspNonce();

        $this->assertNotEmpty($nonce);
        $this->assertStringContainsString("script-src 'self' 'nonce-{$nonce}'", $policy);
        foreach (["default-src 'self'", "object-src 'none'", "base-uri 'self'", "form-action 'self'", "frame-ancestors 'none'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com", "font-src 'self' https://fonts.gstatic.com", "img-src 'self' data:", "connect-src 'self'"] as $directive) {
            $this->assertStringContainsString($directive, $policy);
        }
        $this->assertStringNotContainsString('unsafe-eval', $policy);
    }

    public function test_each_response_gets_a_new_nonce(): void
    {
        $this->csp();
        $first = Vite::cspNonce();
        $this->csp();

        $this->assertNotSame($first, Vite::cspNonce());
    }

    public function test_the_vite_dev_server_is_allowed_only_locally_while_it_runs(): void
    {
        $public = storage_path('framework/testing/public-'.uniqid());
        File::ensureDirectoryExists($public);
        File::put("{$public}/hot", 'http://localhost:5173');
        $this->app->usePublicPath($public);

        try {
            $this->assertStringNotContainsString('localhost:5173', $this->csp());

            $this->app['env'] = 'local';
            $policy = $this->csp();
            $this->assertStringContainsString("script-src 'self' 'nonce-", $policy);
            $this->assertStringContainsString('http://localhost:5173', $policy);
            $this->assertStringContainsString("connect-src 'self' http://localhost:5173 ws://localhost:5173", $policy);
        } finally {
            File::deleteDirectory($public);
        }
    }
}
