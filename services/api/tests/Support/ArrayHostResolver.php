<?php

namespace Tests\Support;

use App\Support\Contracts\HostResolver;

class ArrayHostResolver implements HostResolver
{
    /**
     * @param  array<string, list<string>>  $map
     */
    public function __construct(private array $map = []) {}

    public function resolve(string $host): array
    {
        return $this->map[$host] ?? [];
    }

    /**
     * @param  list<string>  $addresses
     */
    public function set(string $host, array $addresses): void
    {
        $this->map[$host] = $addresses;
    }
}
