<?php

namespace App\Support;

class SsrfGuard
{
    /**
     * Ranges FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE were measured to let
     * through, but that must never be fetched from the server: CGNAT
     * (100.64.0.0/10), the benchmarking block (198.18.0.0/15), IETF protocol
     * space, IPv6 multicast (ff00::/12), the IPv6 documentation prefix
     * (2001:db8::/32) and 6to4 (2002::/16, which embeds an IPv4 address and
     * can therefore smuggle an internal one).
     */
    private const EXTRA_BLOCKED_CIDRS = [
        '0.0.0.0/8',
        '100.64.0.0/10',
        '198.18.0.0/15',
        '192.0.0.0/24',
        'ff00::/12',
        '2001:db8::/32',
        '2002::/16',
    ];

    public static function isPublicAddress(string $address): bool
    {
        if (self::isIpv4Mapped($address)) {
            return false;
        }

        if (filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false) {
            return false;
        }

        foreach (self::EXTRA_BLOCKED_CIDRS as $cidr) {
            if (self::inCidr($address, $cidr)) {
                return false;
            }
        }

        return true;
    }

    /**
     * ::ffff:a.b.c.d is reported public by filter_var even when the embedded
     * address is 127.0.0.1, and no legitimate stream host uses it.
     */
    private static function isIpv4Mapped(string $address): bool
    {
        $binary = inet_pton($address);

        return $binary !== false
            && strlen($binary) === 16
            && substr($binary, 0, 10) === str_repeat("\0", 10)
            && $binary[10] === "\xff"
            && $binary[11] === "\xff";
    }

    private static function inCidr(string $address, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);

        $ip = inet_pton($address);
        $network = inet_pton($subnet);

        if ($ip === false || $network === false || strlen($ip) !== strlen($network)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && substr($ip, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($ip[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }
}
