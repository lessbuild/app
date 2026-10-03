<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use Illuminate\Support\Str;

final class TelemetryRedactor
{
    public const REPLACEMENT = '[REDACTED]';

    /**
     * Replace secrets anywhere in an event before it's stored or shown.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function redact(array $event): array
    {
        return $this->value($event, '', 0);
    }

    /**
     * Redact a value recursively: sensitive keys and OTLP attributes with sensitive names lose their values, strings
     * are scrubbed, and anything nested too deeply is replaced.
     *
     * @param  mixed  $value
     * @param  string  $path
     * @param  int  $depth
     * @return mixed
     */
    private function value(mixed $value, string $path, int $depth): mixed
    {
        if ($depth > (int) config('monitoring.telemetry.max_json_depth')) {
            return self::REPLACEMENT;
        }

        if (is_string($value)) {
            return $this->text($value, $path);
        }

        if (! is_array($value)) {
            return $value;
        }

        $attributeName = $value['key'] ?? $value['name'] ?? null;

        if (is_string($attributeName) && array_key_exists('value', $value) && $this->sensitive($attributeName, $path.'.'.$attributeName)) {
            $value['value'] = is_array($value['value'])
                ? ['stringValue' => self::REPLACEMENT]
                : self::REPLACEMENT;
        }

        foreach ($value as $key => $item) {
            $itemPath = $path === '' ? (string) $key : $path.'.'.$key;
            $value[$key] = $this->sensitive((string) $key, $itemPath)
                ? self::REPLACEMENT
                : $this->value($item, $itemPath, $depth + 1);
        }

        return $value;
    }

    /**
     * Determine whether a key (compared without punctuation and case) or its path matches a configured sensitive name
     * or path.
     *
     * @param  string  $key
     * @param  string  $path
     * @return bool
     */
    private function sensitive(string $key, string $path): bool
    {
        $normalized = (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($key));

        foreach (config('monitoring.telemetry.sensitive_keys') as $pattern) {
            if (Str::is($pattern, $normalized)) {
                return true;
            }
        }

        foreach (config('monitoring.telemetry.redacted_paths') as $pattern) {
            if (Str::is(mb_strtolower($pattern), mb_strtolower($path)) || Str::is(mb_strtolower($pattern), mb_strtolower($key))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Scrub secrets from free text: bearer and basic credentials, ingest tokens, passwords in URLs, sensitive query
     * parameters, and `password=`/`token:`-style assignments.
     *
     * @param  string  $value
     * @param  string  $path
     * @return string
     */
    private function text(string $value, string $path): string
    {
        $value = preg_replace('/\b(Bearer|Basic)\s+[a-z0-9._~+\/=-]+/i', '$1 '.self::REPLACEMENT, $value) ?? self::REPLACEMENT;
        $value = preg_replace('/\bbcn_[a-zA-Z0-9]{64}\b/', self::REPLACEMENT, $value) ?? self::REPLACEMENT;
        $value = preg_replace('/\b([a-z][a-z0-9+.-]*:\/\/)[^\/\s?#]+@/i', '$1'.self::REPLACEMENT.'@', $value) ?? self::REPLACEMENT;
        $value = preg_replace_callback(
            '/([?&;])([^=&#;\s]+)=([^&#;\s]*)/',
            fn (array $matches): string => $this->sensitive(urldecode($matches[2]), $path.'.'.urldecode($matches[2]))
                ? $matches[1].$matches[2].'='.self::REPLACEMENT
                : $matches[0],
            $value,
        ) ?? self::REPLACEMENT;

        $assignment = <<<'REGEX'
/(["']?\b(?:password|passwd|api[_-]?key|access[_-]?token|refresh[_-]?token|client[_-]?secret|secret|token)["']?)(\s*[:=]\s*)("(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|[^\s,;&]+)/i
REGEX;

        return preg_replace_callback(
            $assignment,
            function (array $matches): string {
                $quote = in_array($matches[3][0], ['"', "'"], true) ? $matches[3][0] : '';

                return $matches[1].$matches[2].$quote.self::REPLACEMENT.$quote;
            },
            $value,
        ) ?? self::REPLACEMENT;
    }
}
