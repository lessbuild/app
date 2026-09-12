<?php

namespace App\Support;

class IpRangeMatcher
{
    /**
     * Test whether an IP address belongs to a same-family address or CIDR range.
     *
     * @return bool False for malformed addresses, invalid prefix lengths, or a non-matching range.
     */
    public function contains(string $range, string $ip): bool
    {
        [$network, $prefix] = array_pad(explode('/', trim($range), 2), 2, null);
        $address = @inet_pton($ip);
        $base = @inet_pton($network);
        if ($address === false || $base === false || strlen($address) !== strlen($base)) {
            return false;
        }
        $bits = $prefix === null ? strlen($address) * 8 : filter_var($prefix, FILTER_VALIDATE_INT);
        if ($bits === false || $bits < 0 || $bits > strlen($address) * 8) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        if (substr($address, 0, $bytes) !== substr($base, 0, $bytes)) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($address[$bytes]) & $mask) === (ord($base[$bytes]) & $mask);
    }
}
