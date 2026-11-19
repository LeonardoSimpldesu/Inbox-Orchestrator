<?php

namespace App\Services\Unsubscribe;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * RFC 8058 one-click unsubscribe. The URL comes from an e-mail header anyone
 * can write, so the server only calls public https hosts, pins the connection
 * to the validated IP (no DNS rebinding) and never follows redirects (SSRF).
 */
class OneClickUnsubscriber
{
    public function __construct(private readonly HostResolver $resolver) {}

    public function unsubscribe(string $url): void
    {
        ['host' => $host, 'ip' => $ip] = $this->guard($url);

        try {
            $response = Http::withOptions($this->requestOptions($host, $ip))
                ->withUserAgent('Mozilla/5.0 (compatible; InboxOrchestrator/1.0)')
                ->asForm()
                ->post($url, ['List-Unsubscribe' => 'One-Click']);
        } catch (ConnectionException $e) {
            throw new UnsubscribeFailed(Str::contains($e->getMessage(), ['timed out', 'cURL error 28']) ? 'timeout' : 'request_failed');
        }

        if ($response->status() >= 400) {
            throw new UnsubscribeFailed('http_'.$response->status());
        }
    }

    /**
     * @return array{host: string, ip: string}
     */
    public function guard(string $url): array
    {
        $parts = parse_url($url);
        $host = Str::lower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ($parts === false
            || Str::lower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || ! in_array($parts['port'] ?? 443, [443], true)
            || $host === '') {
            throw new UnsubscribeFailed('blocked_address');
        }

        $ips = $this->resolver->resolve($host);

        if ($ips === [] || array_filter($ips, fn (string $ip) => ! $this->isPublic($ip)) !== []) {
            throw new UnsubscribeFailed('blocked_address');
        }

        return ['host' => $host, 'ip' => $ips[0]];
    }

    /**
     * @return array<string, mixed>
     */
    public function requestOptions(string $host, string $ip): array
    {
        $pinned = str_contains($ip, ':') ? "[{$ip}]" : $ip;

        return [
            'allow_redirects' => false,
            'connect_timeout' => 5,
            'timeout' => 10,
            'curl' => [CURLOPT_RESOLVE => ["{$host}:443:{$pinned}"]],
        ];
    }

    private function isPublic(string $ip): bool
    {
        if (Str::startsWith(Str::lower($ip), '::ffff:') && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $ip = substr($ip, 7);
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false
            && ! $this->isSharedAddressSpace($ip);
    }

    /** 100.64.0.0/10 (carrier-grade NAT) is not covered by FILTER_FLAG_GLOBAL_RANGE. */
    private function isSharedAddressSpace(string $ip): bool
    {
        $long = ip2long($ip);

        return $long !== false && ($long & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000);
    }
}
