<?php

namespace Tests\Unit;

use App\Support\SsrfGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SsrfGuardTest extends TestCase
{
    public static function publicAddresses(): array
    {
        return [
            'example anycast' => ['93.184.216.34'],
            'google dns' => ['8.8.8.8'],
            'cloudflare ipv6' => ['2606:4700:4700::1111'],
            // TEST-NET blocks are unrouteable, so they are not an internal-address
            // risk. filter_var treats them as public and the guard mirrors that.
            'test-net v4' => ['192.0.2.1'],
            'test-net-2 v4' => ['198.51.100.9'],
        ];
    }

    public static function blockedAddresses(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'this network' => ['0.0.0.0'],
            'private class a' => ['10.1.2.3'],
            'private class b' => ['172.16.9.9'],
            'private class c' => ['192.168.4.4'],
            'link local metadata' => ['169.254.169.254'],
            'cgnat' => ['100.64.1.1'],
            'benchmarking' => ['198.18.0.7'],
            'ipv6 loopback' => ['::1'],
            'ipv6 unspecified' => ['::'],
            'ipv6 link local' => ['fe80::1'],
            'ipv6 unique local' => ['fc00::1234'],
            'ipv6 multicast' => ['ff02::1'],
            'ipv6 documentation' => ['2001:db8::1'],
            'ipv4 mapped to loopback' => ['::ffff:127.0.0.1'],
            'ipv4 mapped text form' => ['::ffff:8.8.8.8'],
            'sixtofour embedding loopback' => ['2002:7f00:1::'],
        ];
    }

    #[DataProvider('publicAddresses')]
    public function test_public_addresses_are_allowed(string $address): void
    {
        $this->assertTrue(SsrfGuard::isPublicAddress($address), $address.' should be public');
    }

    #[DataProvider('blockedAddresses')]
    public function test_reserved_and_smuggled_addresses_are_blocked(string $address): void
    {
        $this->assertFalse(SsrfGuard::isPublicAddress($address), $address.' should be blocked');
    }

    public function test_garbage_input_is_blocked(): void
    {
        $this->assertFalse(SsrfGuard::isPublicAddress('not-an-ip'));
        $this->assertFalse(SsrfGuard::isPublicAddress(''));
        $this->assertFalse(SsrfGuard::isPublicAddress('999.999.999.999'));
        $this->assertFalse(SsrfGuard::isPublicAddress('10.0.0.1 8080'));
    }
}
