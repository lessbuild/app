<?php

declare(strict_types=1);

namespace App\Data\Deploy;

/** A verified delivery to the GitHub App webhook: a ping, or an event for one repository of one installation. */
final readonly class GitHubAppWebhook
{
    /**
     * Create a new GitHubAppWebhook instance.
     *
     * A verified webhook delivery from the GitHub App.
     *
     * @param  bool  $isPing  Whether it's GitHub's ping after the App is set up, which needs no action.
     * @param  ?string  $installationId  The App installation the event came through.
     * @param  ?string  $repository  The repository's `owner/name`, lowercased so it matches however it was stored.
     */
    public function __construct(public bool $isPing, public ?string $installationId = null, public ?string $repository = null) {}
}
