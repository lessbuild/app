<?php

namespace App\Modules\Monitor\Services\Telemetry;

use Illuminate\Support\Str;

final class TelemetryRedactor
{
    public const REPLACEMENT = '[REDACTED]';

    /** @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function redact(array $event): array
    {
        return $this->value($event, '', 0);
    }

    private function value(mixed $value, string $path, int $depth): mixed
    {
        if ($depth > (int) config('monitor.beacon.telemetry.max_json_depth')) {
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

    private function sensitive(string $key, string $path): bool
    {
        $normalized = preg_replace('/[^a-z0-9]/', '', mb_strtolower($key));

        foreach (config('monitor.beacon.telemetry.sensitive_keys') as $pattern) {
            if (Str::is($pattern, $normalized)) {
                return true;
            }
        }

        foreach (config('monitor.beacon.telemetry.redacted_paths') as $pattern) {
            if (Str::is(mb_strtolower($pattern), mb_strtolower($path)) || Str::is(mb_strtolower($pattern), mb_strtolower($key))) {
                return true;
            }
        }

        return false;
    }

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
