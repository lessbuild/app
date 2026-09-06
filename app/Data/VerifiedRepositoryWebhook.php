<?php

namespace App\Data;

class VerifiedRepositoryWebhook
{
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

    public function isPreviewEvent(): bool
    {
        return $this->previewAction !== null && $this->pullRequestNumber !== null;
    }
}
