<?php

namespace App\Data;

class VerifiedRepositoryWebhook
{
    /**
     * Carry the verified delivery identity and normalized push or preview event fields.
     *
     * @param  string  $deliveryId  Provider delivery identifier used to deduplicate the webhook.
     * @param  bool  $isPush  Whether the provider event is a repository push.
     * @param  bool  $matchesBranch  Whether the push targets the repository's configured branch.
     * @param  string|null  $revision  Verified push or preview commit SHA, if supplied.
     * @param  string|null  $commitMessage  Push commit message, when present in the payload.
     * @param  'updated'|'closed'|null  $previewAction  Normalized pull/merge-request action, or null for unrelated events.
     * @param  int|null  $pullRequestNumber  Provider pull/merge-request number for a preview event.
     * @param  string|null  $pullRequestTitle  Pull/merge-request title, if supplied.
     * @param  string|null  $sourceBranch  Source branch for the pull/merge request, if supplied.
     */
    public function __construct(
        public readonly string $deliveryId,
        public readonly bool $isPush,
        public readonly bool $matchesBranch,
        public readonly ?string $revision = null,
        public readonly ?string $commitMessage = null,
        public readonly ?string $previewAction = null,
        public readonly ?int $pullRequestNumber = null,
        public readonly ?string $pullRequestTitle = null,
        public readonly ?string $sourceBranch = null,
    ) {}

    /**
     * Determine whether the verified delivery identifies a preview lifecycle event.
     *
     * @return bool True when both the normalized preview action and pull-request number are present.
     */
    public function isPreviewEvent(): bool
    {
        return $this->previewAction !== null && $this->pullRequestNumber !== null;
    }
}
