<?php

declare(strict_types=1);

namespace App\Support;

/** Matches IPv4 and IPv6 addresses against single addresses or CIDR ranges. */
final class IpRange
{
    /**
     * Determine whether a range is a valid address or CIDR range.
     *
     * @param  string  $range
     * @return bool
     */
    public static function isValid(string $range): bool
    {
        [$network, $prefix] = array_pad(explode('/', trim($range), 2), 2, null);
        $base = @inet_pton((string) $network);
        if ($base === false) {
            return false;
        }

        return $prefix === null || (ctype_digit($prefix) && (int) $prefix <= strlen($base) * 8);
    }

    /**
     * Determine whether an address falls in a range of the same family; malformed input never matches.
     *
     * @param  string  $range
     * @param  string  $ip
     * @return bool
     */
    public static function contains(string $range, string $ip): bool
    {
        [$network, $prefix] = array_pad(explode('/', trim($range), 2), 2, null);
        $address = @inet_pton($ip);
        $base = @inet_pton((string) $network);
        if ($address === false || $base === false || strlen($address) !== strlen($base)) {
            return false;
        }
        $bits = $prefix === null ? strlen($address) * 8 : (ctype_digit($prefix) ? (int) $prefix : -1);
        if ($bits < 0 || $bits > strlen($address) * 8) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if (substr($address, 0, $bytes) !== substr($base, 0, $bytes)) {
            return false;
        }
        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($address[$bytes]) & $mask) === (ord($base[$bytes]) & $mask);
    }

    /**
     * Determine whether an address is in any of the ranges.
     *
     * @param  list<string>  $ranges
     * @param  string  $ip
     * @return bool
     */
    public static function any(array $ranges, string $ip): bool
    {
        foreach ($ranges as $range) {
            if (self::contains($range, $ip)) {
                return true;
            }
        }

        return false;
    }
}
