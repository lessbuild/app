<?php

declare(strict_types=1);

namespace App\Support\Deploy;

use App\Data\Deploy\VerifiedRepositoryWebhook;
use App\Models\Repository;

/**
 * Decides whether a pull request may run as a preview. A preview runs the pull request's code on the account's server,
 * so only pull requests from the repository itself into its deploy branch qualify. Forks are refused until previews
 * can be isolated from the host, and events that don't say where they come from are refused rather than trusted.
 */
final class PreviewTrust
{
    /**
     * Explain why the pull request can't have a preview, as the webhook status to answer with, or return null when it
     * may.
     *
     * @param  Repository  $source
     * @param  VerifiedRepositoryWebhook  $webhook
     * @return string|null
     */
    public static function refusal(Repository $source, VerifiedRepositoryWebhook $webhook): ?string
    {
        if ($webhook->targetBranch === null) {
            return 'preview_target_unverified';
        }
        if (! hash_equals($source->branch, $webhook->targetBranch)) {
            return 'preview_target_ignored';
        }
        if ($webhook->targetRepository === null) {
            return 'preview_source_unverified';
        }
        $host = $source->provider?->repositoryHost();
        $configured = self::repositoryPath($source->url, $host);
        $target = self::repositoryPath($webhook->targetRepository, $host);
        if ($configured === '' || $target === '' || ! hash_equals($configured, $target)) {
            return 'preview_source_ignored';
        }

        return match ($webhook->isFork) {
            true => 'preview_fork_blocked',
            null => 'preview_source_unverified',
            false => null,
        };
    }

    /**
     * Reduce a repository URL or a Git host's full name to its lowercased `owner/name` path, without the host or a
     * `.git` suffix.
     *
     * @param  string  $value
     * @param  string|null  $host
     * @return string
     */
    private static function repositoryPath(string $value, ?string $host): string
    {
        $value = strtolower(trim($value));
        if ($host !== null && str_starts_with($value, strtolower($host).'/')) {
            $value = substr($value, strlen($host) + 1);
        }

        return str_ends_with($value, '.git') ? substr($value, 0, -4) : $value;
    }
}
