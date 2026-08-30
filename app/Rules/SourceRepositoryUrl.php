<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SourceRepositoryUrl implements ValidationRule
{
    private const HOST_PATTERNS = [
        'github.com' => '[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?/[A-Za-z0-9._-]{1,100}',
        'gitlab.com' => '[A-Za-z0-9_-][A-Za-z0-9_.-]*(?:/[A-Za-z0-9_-][A-Za-z0-9_.-]*)+',
        'bitbucket.org' => '[A-Za-z0-9_-]+/[A-Za-z0-9._-]+',
    ];

    public function __construct(private readonly ?string $expectedHost = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $this->fail($attribute, $fail);

            return;
        }

        $host = strtolower(str($value)->before('/')->toString());
        $pattern = self::HOST_PATTERNS[$host] ?? null;
        if ($pattern === null || ($this->expectedHost !== null && $host !== $this->expectedHost)) {
            $this->fail($attribute, $fail);

            return;
        }

        if (! preg_match('#^'.preg_quote($host, '#').'/'.$pattern.'\.git$#', $value)) {
            $this->fail($attribute, $fail);

            return;
        }

        foreach (explode('/', str($value)->after('/')->beforeLast('.git')->toString()) as $segment) {
            if (in_array($segment, ['.', '..'], true)) {
                $this->fail($attribute, $fail);

                return;
            }
        }
    }

    private function fail(string $attribute, Closure $fail): void
    {
        $host = $this->expectedHost ?? __('a supported source control host');
        $fail(__('The :attribute must identify a repository on :host.', [
            'attribute' => $attribute,
            'host' => $host,
        ]));
    }
}
