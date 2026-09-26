<?php

declare(strict_types=1);

namespace App\Domain\Projects\Support;

/** Turns what people type ("https://Shop.Example.com/path", "bücher.example") into a comparable hostname. */
final class Hostname
{
    /** @return string|null the ASCII hostname, or null if it isn't a public hostname we can verify */
    public static function normalize(string $input): ?string
    {
        $host = trim($input);
        if (str_contains($host, '://')) {
            $host = (string) parse_url($host, PHP_URL_HOST);
        }
        $host = strtolower(rtrim(explode('/', $host, 2)[0], '.'));
        if (str_contains($host, ':') || $host === '') {
            return null; // ports, IPv6 literals and empty input
        }

        $ascii = idn_to_ascii($host, IDNA_DEFAULT | IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
        if ($ascii === false || strlen($ascii) > 253 || filter_var($ascii, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $labels = explode('.', $ascii);
        if (count($labels) < 2 || preg_match('/^[a-z]{2,63}$|^xn--[a-z0-9-]{1,59}$/', end($labels)) !== 1) {
            return null; // needs a real top-level domain; rules out "localhost" and "intranet"
        }
        foreach ($labels as $label) {
            if (preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)$/', $label) !== 1) {
                return null;
            }
        }

        return $ascii;
    }

    /** The name people recognise, for display. */
    public static function display(string $ascii): string
    {
        $unicode = idn_to_utf8($ascii, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return $unicode !== false ? $unicode : $ascii;
    }
}
