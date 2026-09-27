<?php

declare(strict_types=1);

namespace App\Data\Deploy;

final readonly class VerifiedRepositoryWebhook
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
     * @param  string|null  $targetBranch  Target branch for the pull/merge request, if supplied.
     * @param  bool|null  $isFork  Whether the source repository differs from the target repository, or null when the provider did not supply enough metadata.
     * @param  string|null  $targetRepository  Provider identity of the pull/merge request target repository or project, if supplied.
     * @param  list<string>|null  $changedPaths  Bounded normalized paths from a push payload, or null when the provider did not provide them.
     */
    public function __construct(
        public string $deliveryId,
        public bool $isPush,
        public bool $matchesBranch,
        public ?string $revision = null,
        public ?string $commitMessage = null,
        public ?string $previewAction = null,
        public ?int $pullRequestNumber = null,
        public ?string $pullRequestTitle = null,
        public ?string $sourceBranch = null,
        public ?string $targetBranch = null,
        public ?bool $isFork = null,
        public ?string $targetRepository = null,
        public ?array $changedPaths = null,
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
