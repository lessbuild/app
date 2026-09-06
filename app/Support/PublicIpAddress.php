<?php

namespace App\Support;

final class PublicIpAddress
{
    /**
     * Determine whether an IPv4 or IPv6 literal is a globally routable unicast address.
     *
     * @param  string  $address  The unbracketed IP literal to validate.
     * @return bool Whether the address may be used as a public network destination.
     */
    public static function isValid(string $address): bool
    {
        // NO_RES_RANGE no longer rejects documentation IPv6 ranges on PHP 8.5.
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }

        $packed = inet_pton($address);

        // PHP's global-range filter permits multicast, which cannot be a server endpoint.
        return strlen($packed) === 4
            ? ord($packed[0]) < 224
            : ord($packed[0]) !== 255;
    }
}
