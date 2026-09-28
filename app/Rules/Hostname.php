<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A hostname (or IP address), optionally with a port, and nothing else. */
final class Hostname implements ValidationRule
{
    /**
     * Accepts a bare host (a DNS name, `localhost` or an IP address) with an optional port, and rejects anything that
     * would make it a URL: a scheme-less path, query, fragment or credentials.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  Closure  $fail
     * @return void
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = is_string($value) && $value !== '' ? parse_url('http://'.$value) : false;
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        $valid = is_array($parts) && is_string($host)
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment']) && ($parts['path'] ?? '') === ''
            && ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false);
        if (! $valid) {
            $fail(__('Enter a hostname like shop.example.com, without https:// or a path.'));
        }
    }
}
