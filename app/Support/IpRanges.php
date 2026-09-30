<?php

declare(strict_types=1);

namespace App\Support;

/** Matches addresses against lists of addresses and networks (CIDR), for IPv4 and IPv6. */
final class IpRanges
{
    /**
     * Determine whether an address is in the list, exactly or inside one of its networks.
     *
     * @param  list<string>  $ranges
     * @param  string  $ip
     * @return bool
     */
    public static function contains(array $ranges, string $ip): bool
    {
        $address = @inet_pton($ip);
        if ($address === false) {
            return false;
        }
        foreach ($ranges as $range) {
            [$network, $bits] = array_pad(explode('/', trim($range), 2), 2, null);
            $prefix = @inet_pton((string) $network);
            if ($prefix === false || strlen($prefix) !== strlen($address)) {
                continue;
            }
            $bits = $bits === null ? strlen($prefix) * 8 : max(0, min(strlen($prefix) * 8, (int) $bits));
            $bytes = intdiv($bits, 8);
            $remainder = $bits % 8;
            if (strncmp($address, $prefix, $bytes) !== 0) {
                continue;
            }
            if ($remainder === 0 || ((ord($address[$bytes]) ^ ord($prefix[$bytes])) & (0xFF << (8 - $remainder)) & 0xFF) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether a string is an address or a network in CIDR notation.
     *
     * @param  string  $range
     * @return bool
     */
    public static function valid(string $range): bool
    {
        [$network, $bits] = array_pad(explode('/', trim($range), 2), 2, null);
        $packed = @inet_pton((string) $network);

        return $packed !== false && ($bits === null || (ctype_digit($bits) && (int) $bits <= strlen($packed) * 8));
    }
}
