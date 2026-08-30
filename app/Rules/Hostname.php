<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Hostname implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail(__('The :attribute must be a valid hostname.', ['attribute' => $attribute]));

            return;
        }

        $parts = parse_url('http://'.$value);
        $host = $parts['host'] ?? null;
        $hasUnexpectedParts = isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (($parts['path'] ?? '') !== '');
        $validHost = is_string($host) && (
            $host === 'localhost'
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
        );
        $validPort = ! isset($parts['port']) || ($parts['port'] >= 1 && $parts['port'] <= 65535);

        if ($hasUnexpectedParts || ! $validHost || ! $validPort) {
            $fail(__('The :attribute must be a hostname without a path, query, or fragment.', [
                'attribute' => $attribute,
            ]));
        }
    }
}
