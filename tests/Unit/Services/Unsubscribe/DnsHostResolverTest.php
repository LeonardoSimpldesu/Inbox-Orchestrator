<?php

namespace Tests\Unit\Services\Unsubscribe;

use App\Services\Unsubscribe\DnsHostResolver;
use App\Services\Unsubscribe\HostResolver;
use Tests\TestCase;

class DnsHostResolverTest extends TestCase
{
    public function test_ip_literals_resolve_to_themselves(): void
    {
        $resolver = new DnsHostResolver;

        $this->assertSame(['93.184.216.34'], $resolver->resolve('93.184.216.34'));
        $this->assertSame(['::1'], $resolver->resolve('::1'));
    }

    public function test_hosts_file_names_resolve(): void
    {
        $this->assertContains('127.0.0.1', (new DnsHostResolver)->resolve('localhost'));
    }

    public function test_unknown_host_resolves_to_nothing(): void
    {
        $this->assertSame([], (new DnsHostResolver)->resolve('does-not-exist.invalid'));
    }

    public function test_is_bound_in_the_container(): void
    {
        $this->assertInstanceOf(DnsHostResolver::class, $this->app->make(HostResolver::class));
    }
}
