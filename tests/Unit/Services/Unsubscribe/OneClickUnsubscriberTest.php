<?php

namespace Tests\Unit\Services\Unsubscribe;

use App\Services\Unsubscribe\HostResolver;
use App\Services\Unsubscribe\OneClickUnsubscriber;
use App\Services\Unsubscribe\UnsubscribeFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OneClickUnsubscriberTest extends TestCase
{
    /** @var array<string, list<string>> */
    private array $dns = ['news.example.com' => ['93.184.216.34']];

    private function unsubscriber(): OneClickUnsubscriber
    {
        $resolver = new class($this->dns) implements HostResolver
        {
            public function __construct(private array $dns) {}

            public function resolve(string $host): array
            {
                return $this->dns[$host] ?? [];
            }
        };

        return new OneClickUnsubscriber($resolver);
    }

    private function assertFailsWith(string $code, string $url): void
    {
        try {
            $this->unsubscriber()->unsubscribe($url);
            $this->fail("Expected UnsubscribeFailed({$code})");
        } catch (UnsubscribeFailed $e) {
            $this->assertSame($code, $e->getMessage());
        }
    }

    public function test_posts_one_click_body(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $this->unsubscriber()->unsubscribe('https://news.example.com/u?id=abc');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://news.example.com/u?id=abc'
            && $request->body() === 'List-Unsubscribe=One-Click'
            && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded'));
    }

    public function test_3xx_counts_as_success_and_is_not_followed(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin'])]);

        $this->unsubscriber()->unsubscribe('https://news.example.com/u');

        Http::assertSentCount(1);
    }

    public function test_4xx_5xx_fail_with_status_code(): void
    {
        Http::fake(['*' => Http::sequence()->push('', 404)->push('', 503)]);

        $this->assertFailsWith('http_404', 'https://news.example.com/u');
        $this->assertFailsWith('http_503', 'https://news.example.com/u');
    }

    public function test_timeout_and_connection_errors(): void
    {
        $errors = ['cURL error 28: Operation timed out after 10001 milliseconds', 'cURL error 7: Failed to connect'];
        Http::fake(function () use (&$errors) {
            throw new ConnectionException(array_shift($errors));
        });

        $this->assertFailsWith('timeout', 'https://news.example.com/u');
        $this->assertFailsWith('request_failed', 'https://news.example.com/u');
    }

    public function test_rejects_http_userinfo_and_odd_ports(): void
    {
        Http::fake();

        $this->assertFailsWith('blocked_address', 'http://news.example.com/u');
        $this->assertFailsWith('blocked_address', 'https://user:pass@news.example.com/u');
        $this->assertFailsWith('blocked_address', 'https://news.example.com:8443/u');
        $this->assertFailsWith('blocked_address', 'not a url');
        $this->assertFailsWith('blocked_address', 'https:///nohost');

        Http::assertNothingSent();
    }

    public function test_allows_explicit_443(): void
    {
        Http::fake(['*' => Http::response('', 204)]);

        $this->unsubscriber()->unsubscribe('https://news.example.com:443/u');

        Http::assertSentCount(1);
    }

    public function test_blocks_private_loopback_linklocal_and_metadata_ips(): void
    {
        Http::fake();
        $blocked = ['10.0.0.5', '127.0.0.1', '169.254.169.254', '192.168.1.10', '172.16.0.1', '100.64.0.1', '0.0.0.0', '::1', 'fc00::1', 'fe80::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1'];

        foreach ($blocked as $i => $ip) {
            $this->dns["host{$i}.test"] = [$ip];
            $this->assertFailsWith('blocked_address', "https://host{$i}.test/u");
        }

        $this->assertFailsWith('blocked_address', 'https://127.0.0.1/u');
        $this->assertFailsWith('blocked_address', 'https://[::1]/u');
        Http::assertNothingSent();
    }

    public function test_blocks_host_with_any_private_ip(): void
    {
        Http::fake();
        $this->dns['mixed.test'] = ['93.184.216.34', '10.0.0.1'];

        $this->assertFailsWith('blocked_address', 'https://mixed.test/u');
        Http::assertNothingSent();
    }

    public function test_blocks_unresolvable_host(): void
    {
        Http::fake();

        $this->assertFailsWith('blocked_address', 'https://nowhere.test/u');
    }

    public function test_pins_connection_to_validated_ip(): void
    {
        $this->dns['v6.test'] = ['2606:4700::6810:84e5'];
        $unsubscriber = $this->unsubscriber();

        $this->assertSame(['host' => 'news.example.com', 'ip' => '93.184.216.34'], $unsubscriber->guard('https://NEWS.example.com/u'));

        $options = $unsubscriber->requestOptions('news.example.com', '93.184.216.34');
        $this->assertSame(['news.example.com:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame(5, $options['connect_timeout']);
        $this->assertSame(10, $options['timeout']);

        $this->assertSame(['v6.test:443:[2606:4700::6810:84e5]'], $unsubscriber->requestOptions('v6.test', '2606:4700::6810:84e5')['curl'][CURLOPT_RESOLVE]);
    }
}
