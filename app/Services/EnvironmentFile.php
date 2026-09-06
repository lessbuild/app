<?php

namespace App\Services;

class EnvironmentFile
{
    /** @param array<string, scalar|null> $variables */
    public function merge(string $base, array $variables): string
    {
        foreach ($variables as $key => $value) {
            if (! preg_match('/\A[A-Z_][A-Z0-9_]*\z/D', $key)) {
                continue;
            }
            $base = preg_replace('/^'.preg_quote($key, '/').'\s*=.*$/m', '', $base) ?? $base;
            $string = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                $value === null => '',
                default => (string) $value,
            };
            $escaped = str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', '\\n'], $string);
            $base = rtrim($base)."\n{$key}=\"{$escaped}\"\n";
        }

        return ltrim($base);
    }
}
