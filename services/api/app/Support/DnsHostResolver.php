<?php

namespace App\Support;

use App\Support\Contracts\HostResolver;

class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $addresses = [];

        foreach (@gethostbynamel($host) ?: [] as $address) {
            $addresses[] = $address;
        }

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
