<?php

namespace App\Data;

class GitHubAppWebhookData
{
    /**
     * Carry the protocol outcome and installation identity after raw-body verification.
     *
     * @param  bool  $isPing  Whether the payload is a GitHub ping that needs no repository lookup.
     * @param  int|null  $installationId  Installation identity for non-ping events.
     * @param  string|null  $repositoryFullName  GitHub owner/repository identity for non-ping events.
     */
    public function __construct(
        public readonly bool $isPing,
        public readonly ?int $installationId = null,
        public readonly ?string $repositoryFullName = null,
    ) {}
}
