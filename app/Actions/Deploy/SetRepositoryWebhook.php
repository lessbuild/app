<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Enums\ProviderType;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class SetRepositoryWebhook
{
    /**
     * Turn the push webhook on (with a new secret, returned once for the Git host's settings) or off (returns null).
     * GitLab signs with a `whsec_` key; GitHub and Bitbucket with a shared secret.
     *
     * @param  User  $actor
     * @param  Repository  $repository
     * @param  bool  $enabled
     * @return string|null
     */
    public function handle(User $actor, Repository $repository, bool $enabled): ?string
    {
        Gate::forUser($actor)->authorize('update', $repository);
        if (! $enabled) {
            $repository->forceFill(['webhook_enabled' => false, 'webhook_secret' => null])->save();

            return null;
        }
        $secret = $repository->provider?->type === ProviderType::GitLab ? 'whsec_'.base64_encode(random_bytes(32)) : Str::random(48);
        $repository->forceFill(['webhook_enabled' => true, 'webhook_secret' => $secret])->save();

        return $secret;
    }
}
