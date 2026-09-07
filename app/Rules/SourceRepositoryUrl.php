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

    /**
     * Optionally restrict repository validation to one supported source-control host.
     *
     * @param  string|null  $expectedHost  Lowercase host name to require, or null to accept any supported host.
     */
    public function __construct(private readonly ?string $expectedHost = null) {}

    /**
     * Normalize common HTTPS and SSH clone URL forms without validating the repository.
     *
     * @param  string  $value  Candidate repository URL or host/path string.
     * @return string Trimmed host/path form with a lowercase supported host and a .git suffix; invalid input may still require rejection.
     */
    public static function normalize(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('#^(?:https?://|ssh://git@)#i', '', $value) ?? $value;
        $value = preg_replace('#^git@(github\.com|gitlab\.com|bitbucket\.org):#i', '$1/', $value) ?? $value;
        $value = preg_replace_callback(
            '#^(github\.com|gitlab\.com|bitbucket\.org)/#i',
            static fn (array $matches): string => strtolower($matches[1]).'/',
            $value,
        ) ?? $value;
        $value = rtrim($value, '/');
        $value = preg_replace('/\.git$/i', '', $value) ?? $value;

        return $value.'.git';
    }

    /**
     * Validate a normalized clone path against the supported host and repository syntax.
     *
     * @param  string  $attribute  Attribute name included in the translated failure message.
     * @param  mixed  $value  Expected host/path.git string; no URL scheme or SSH prefix is accepted.
     * @param  Closure(string, ?string=): object  $fail  Records a failure and returns a potentially translated validation message.
     * @return void Failures are reported through the callback.
     */
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

    /**
     * Report the host-specific repository validation failure.
     *
     * @param  string  $attribute  Attribute name included in the translated failure message.
     * @param  Closure(string, ?string=): object  $fail  Records a failure and returns a potentially translated validation message.
     * @return void The callback receives the translated failure message.
     */
    private function fail(string $attribute, Closure $fail): void
    {
        $host = $this->expectedHost ?? __('a supported source control host');
        $fail(__('The :attribute must identify a repository on :host.', [
            'attribute' => $attribute,
            'host' => $host,
        ]));
    }
}
