<?php

namespace App\Support\Contracts;

interface HostResolver
{
    /**
     * @return list<string> IP addresses the host resolves to (empty when it does not resolve)
     */
    public function resolve(string $host): array;
}
