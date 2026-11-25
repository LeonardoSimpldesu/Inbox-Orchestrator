<?php

namespace Tests\Feature;

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
}
