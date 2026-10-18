<?php

declare(strict_types=1);

namespace App\Support;

/** Turns what people type ("https://Shop.Example.com/path", "bücher.example") into a comparable hostname. */
final class Hostname
{
    /**
     * Turn what someone typed (possibly a URL, mixed case, a trailing dot or an internationalised name) into the
     * lowercase ASCII hostname we store and verify. Returns null for anything that isn't a public DNS name: IPs,
     * ports, `localhost`, single-label names and malformed labels.
     *
     * @param  string  $input
     * @return string|null the ASCII hostname, or null if it isn't a public hostname we can verify
     */
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

    /**
     * Convert an ASCII hostname to the name people recognise, for display.
     *
     * @param  string  $ascii
     * @return string
     */
    public static function display(string $ascii): string
    {
        $unicode = idn_to_utf8($ascii, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return $unicode !== false ? $unicode : $ascii;
    }

    /**
     * Clean what someone typed into a hostname field: strip a leading `http://` or `https://` and trailing slashes,
     * and lowercase it. Forms run this before validation; the Hostname rule then checks what's left.
     *
     * @param  mixed  $value
     * @return string
     */
    public static function fromInput(mixed $value): string
    {
        return strtolower(rtrim(preg_replace('#^https?://#i', '', trim(is_string($value) ? $value : '')) ?? '', '/'));
    }

    /**
     * Endings registered under two labels, so the domain someone owns is three labels long (shop.co.uk). The common
     * ones only: a domain under a rarer one is shown one label shorter than it is, and the full name still works.
     *
     * @var list<string>
     */
    private const TWO_LABEL_SUFFIXES = [
        'co.uk', 'org.uk', 'me.uk', 'ltd.uk', 'plc.uk', 'net.uk', 'ac.uk', 'gov.uk', 'com.au', 'net.au', 'org.au', 'co.nz',
        'org.nz', 'co.za', 'com.br', 'com.mx', 'com.ar', 'com.tr', 'com.sg', 'com.my', 'com.hk', 'com.cn', 'com.tw', 'co.jp',
        'co.kr', 'co.in', 'co.id', 'co.il', 'co.th', 'com.ph', 'com.pk', 'com.ng', 'com.eg', 'com.sa', 'com.ua', 'com.pl',
    ];

    /**
     * Get the domain someone registered that a hostname is under: shop.example.com → example.com, shop.co.uk → shop.co.uk.
     *
     * @param  string  $hostname  ASCII, lowercase.
     * @return string
     */
    public static function root(string $hostname): string
    {
        $labels = explode('.', trim($hostname, '.'));
        $keep = count($labels) >= 3 && in_array(implode('.', array_slice($labels, -2)), self::TWO_LABEL_SUFFIXES, true) ? 3 : 2;

        return implode('.', array_slice($labels, -$keep));
    }

    /**
     * Get a DNS name as most providers' Host field wants it: without the registered domain they add themselves
     * (_verify.shop.example.com → _verify.shop), or "@" for the domain itself.
     *
     * @param  string  $name  ASCII, lowercase.
     * @return string
     */
    public static function host(string $name): string
    {
        $name = trim($name, '.');
        $root = self::root($name);

        return $name === $root ? '@' : substr($name, 0, -strlen('.'.$root));
    }
}
