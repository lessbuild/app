<?php

namespace App\Support;

class RepositoryPath
{
    public const MAX_PATTERN_BYTES = 255;

    public const MAX_PATH_BYTES = 512;

    public const MAX_CHANGED_PATHS = 200;

    /**
     * Normalize a provider-reported repository path into a safe relative POSIX path.
     *
     * @param  mixed  $value  Untrusted provider path data.
     * @return string|null A normalized relative path, or null when the value is unsafe or unbounded.
     */
    public static function normalize(mixed $value): ?string
    {
        return self::normalizeRelative($value, self::MAX_PATH_BYTES, false);
    }

    /**
     * Normalize a user-configured relative glob used for automatic deployment impact checks.
     *
     * @param  mixed  $value  Untrusted request or persisted path-pattern data.
     * @return string|null A normalized relative pattern, or null when the pattern is invalid.
     */
    public static function normalizePattern(mixed $value): ?string
    {
        return self::normalizeRelative($value, self::MAX_PATTERN_BYTES, true);
    }

    /**
     * Match a normalized relative repository path against a bounded glob pattern.
     *
     * A single asterisk matches characters within one path segment. Two asterisks
     * match across path segments, and a question mark matches one non-separator
     * character.
     *
     * @param  string  $pattern  Relative pattern previously accepted by normalizePattern().
     * @param  string  $path  Relative path previously accepted by normalize().
     * @return bool Whether the path matches the pattern.
     */
    public static function matches(string $pattern, string $path): bool
    {
        $pattern = self::normalizePattern($pattern);
        $path = self::normalize($path);
        if ($pattern === null || $path === null) {
            return false;
        }

        $characters = preg_split('//u', $pattern, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            return false;
        }

        $expression = '';
        for ($index = 0, $count = count($characters); $index < $count; $index++) {
            $character = $characters[$index];
            if ($character === '*' && ($characters[$index + 1] ?? null) === '*') {
                $expression .= '.*';
                $index++;

                continue;
            }
            if ($character === '*') {
                $expression .= '[^/]*';

                continue;
            }
            if ($character === '?') {
                $expression .= '[^/]';

                continue;
            }

            $expression .= preg_quote($character, '~');
        }

        return preg_match('~\A'.$expression.'\z~u', $path) === 1;
    }

    /**
     * Normalize a relative path or pattern without allowing traversal or absolute paths.
     *
     * @param  mixed  $value  Candidate path.
     * @param  int  $maxBytes  Maximum encoded length.
     * @param  bool  $allowWildcards  Whether wildcard characters are accepted.
     * @return string|null Safe normalized value, or null when validation fails.
     */
    private static function normalizeRelative(mixed $value, int $maxBytes, bool $allowWildcards): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = str_replace('\\', '/', trim($value));
        if ($value === '' || strlen($value) > $maxBytes || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return null;
        }
        if (str_starts_with($value, '/') || preg_match('/\A[A-Za-z]:\//', $value)) {
            return null;
        }

        while (str_starts_with($value, './')) {
            $value = substr($value, 2);
        }
        if ($value === '') {
            return null;
        }

        foreach (explode('/', $value) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
            if (! $allowWildcards && (str_contains($segment, '*') || str_contains($segment, '?'))) {
                return null;
            }
        }

        return $value;
    }
}
