<?php

declare(strict_types=1);

namespace App\Support;

/** Checks the branch, tag or commit names people ask to deploy, so they're safe to hand to git. */
final class GitRef
{
    /**
     * The pattern a ref must match: git's own rules, narrowed (no leading dash or dot, no "..", no "@{", no ending
     * ".lock" or slash, printable ASCII without spaces or git's special characters).
     */
    public const PATTERN = '/\A(?![-.\/])(?!.*\.\.)(?!.*@\{)(?!.*\/\/)(?!.*\.lock\z)(?!.*[\/.]\z)[A-Za-z0-9._\/+-]{1,200}\z/';

    /**
     * Normalise a ref as typed, or return null when it isn't one git would accept.
     *
     * @param  string|null  $input
     * @return string|null
     */
    public static function normalize(?string $input): ?string
    {
        $ref = trim((string) $input);
        if (str_starts_with($ref, 'refs/heads/')) {
            $ref = substr($ref, 11);
        } elseif (str_starts_with($ref, 'refs/tags/')) {
            $ref = substr($ref, 10);
        }

        return $ref !== '' && preg_match(self::PATTERN, $ref) === 1 ? $ref : null;
    }
}
