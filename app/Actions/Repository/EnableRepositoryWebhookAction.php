<?php

namespace App\Actions\Repository;

use App\Models\Provider;
use App\Models\Repository;
use Illuminate\Support\Str;

class EnableRepositoryWebhookAction
{
    /**
     * Enable a repository webhook using the validated GitLab token or a generated secret for other providers.
     *
     * @param  Repository  $repository  Repository whose webhook settings are being changed.
     * @param  string|null  $signingToken  Validated GitLab token, when the repository uses GitLab.
     * @return string|null A newly generated secret to display once, or null when the supplied token is not displayable.
     */
    public function handle(Repository $repository, ?string $signingToken = null): ?string
    {
        $repository->loadMissing('provider');
        $usesGitLab = $repository->provider->provider === Provider::TYPE_GITLAB;
        $secret = $usesGitLab ? $signingToken : Str::random(64);

        $repository->update([
            'webhook_enabled' => true,
            'webhook_secret' => $secret,
        ]);

        return $usesGitLab ? null : $secret;
    }
}
