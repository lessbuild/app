<?php

namespace App\Actions\Repository;

use App\Models\Repository;

class DisableRepositoryWebhookAction
{
    /**
     * Disable a repository webhook and clear pending deployment state.
     *
     * @param  Repository  $repository  Repository whose webhook settings are being cleared.
     */
    public function handle(Repository $repository): void
    {
        $repository->update([
            'webhook_enabled' => false,
            'webhook_secret' => null,
            'webhook_pending' => false,
            'webhook_pending_revision' => null,
            'webhook_pending_commit_message' => null,
        ]);
    }
}
