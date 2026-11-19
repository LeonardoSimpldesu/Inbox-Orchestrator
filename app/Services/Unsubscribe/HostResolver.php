<?php

namespace App\Services\Unsubscribe;

interface HostResolver
{
    /**
     * @return list<string> every IPv4 and IPv6 address of the host
     */
    public function resolve(string $host): array;
}
