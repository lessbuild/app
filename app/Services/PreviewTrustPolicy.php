<?php

namespace App\Services;

use App\Data\PreviewTrustDecision;
use App\Data\VerifiedRepositoryWebhook;
use App\Models\Repository;

class PreviewTrustPolicy
{
    /**
     * Decide whether a nonclosed preview event has enough trusted repository context to provision code.
     *
     * Forks remain blocked until BuildPusher can prove host-level isolation for untrusted code. Missing
     * provider metadata is also denied instead of being treated as a same-repository event.
     *
     * @param  Repository  $source  Repository whose configured deployment branch owns the preview.
     * @param  VerifiedRepositoryWebhook  $webhook  Authenticated provider metadata for the pull request.
     * @return PreviewTrustDecision The named safe-admission outcome.
     */
    public function evaluate(Repository $source, VerifiedRepositoryWebhook $webhook): PreviewTrustDecision
    {
        if ($webhook->targetBranch === null) {
            return new PreviewTrustDecision(PreviewTrustDecision::TARGET_BRANCH_UNVERIFIED);
        }

        if (! hash_equals((string) $source->branch, $webhook->targetBranch)) {
            return new PreviewTrustDecision(PreviewTrustDecision::TARGET_BRANCH_MISMATCH);
        }

        if ($webhook->targetRepository === null) {
            return new PreviewTrustDecision(PreviewTrustDecision::SOURCE_UNVERIFIED);
        }

        $source->loadMissing('provider');
        $host = $source->provider?->repositoryHost();
        $configured = $this->repositoryPath((string) $source->url, $host);
        $target = $this->repositoryPath($webhook->targetRepository, $host);
        if ($configured === '' || $target === '' || ! hash_equals($configured, $target)) {
            return new PreviewTrustDecision(PreviewTrustDecision::SOURCE_MISMATCH);
        }

        if ($webhook->isFork === true) {
            return new PreviewTrustDecision(PreviewTrustDecision::FORK_NOT_ALLOWED);
        }

        if ($webhook->isFork === null) {
            return new PreviewTrustDecision(PreviewTrustDecision::SOURCE_UNVERIFIED);
        }

        return new PreviewTrustDecision(PreviewTrustDecision::ALLOWED);
    }

    /**
     * Normalize a provider repository URL or full name to its owner/path identity.
     *
     * @param  string  $value  Repository URL path or provider full name.
     * @param  string|null  $host  Provider host to remove when a payload includes it.
     * @return string Lowercase owner/path identity without a clone suffix.
     */
    private function repositoryPath(string $value, ?string $host): string
    {
        $value = strtolower(trim($value));
        if ($host !== null && str_starts_with($value, strtolower($host).'/')) {
            $value = substr($value, strlen($host) + 1);
        }

        return str_ends_with($value, '.git') ? substr($value, 0, -4) : $value;
    }
}
