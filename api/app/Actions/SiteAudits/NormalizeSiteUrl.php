<?php

declare(strict_types=1);

namespace App\Actions\SiteAudits;

use App\Exceptions\AccountRuleViolation;

final class NormalizeSiteUrl
{
    /**
     * Turn what someone typed ("example.com", "https://Example.com/pricing") into a full http(s) URL, or refuse it.
     * Whether the address is public is checked when it's visited.
     *
     * @param  string  $input
     * @param  string  $field  the form field to attach an error to
     * @return string
     *
     * @throws AccountRuleViolation
     */
    public function handle(string $input, string $field = 'url'): string
    {
        $url = trim($input);
        if ($url !== '' && preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) !== 1) {
            $url = 'https://'.$url;
        }
        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || ! str_contains($host, '.') || isset($parts['user']) || isset($parts['pass']) || mb_strlen($url) > 2048) {
            throw new AccountRuleViolation($field, __('Enter a website address, such as example.com.'));
        }

        return $scheme.'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
