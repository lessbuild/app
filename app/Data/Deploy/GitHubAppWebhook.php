<?php

declare(strict_types=1);

namespace App\Data\Deploy;

/** A verified delivery to the GitHub App webhook: a ping, or an event for one repository of one installation. */
final readonly class GitHubAppWebhook
{
    public function __construct(public bool $isPing, public ?string $installationId = null, public ?string $repository = null) {}
}
