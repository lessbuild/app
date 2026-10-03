<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Exceptions\AccountRuleViolation;
use App\Jobs\Deploy\RunSecretSync;
use App\Models\Environment;
use App\Models\SecretSync;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class SaveSecretSync
{
    /**
     * How many sources one environment can sync from.
     *
     * @var int
     */
    public const MAX_PER_ENVIRONMENT = 3;

    /**
     * Connect a password manager to the environment and sync it straight away: Doppler (a service token), 1Password
     * Connect (the server's address, a token, the vault and item IDs) or AWS Secrets Manager (a region, an access key
     * that can read the secret, and the secret's name or ARN).
     *
     * @param  User  $actor
     * @param  Environment  $environment
     * @param  string  $provider
     * @param  string  $name
     * @param  array<string, string|null>  $settings
     * @return SecretSync
     */
    public function handle(User $actor, Environment $environment, string $provider, string $name, array $settings): SecretSync
    {
        Gate::forUser($actor)->authorize('configureDeploy', $environment);
        if (SecretSync::query()->where('environment_id', $environment->id)->count() >= self::MAX_PER_ENVIRONMENT) {
            throw new AccountRuleViolation('provider', __('An environment can sync from up to :count sources.', ['count' => self::MAX_PER_ENVIRONMENT]));
        }
        $value = fn (string $key): string => trim((string) ($settings[$key] ?? ''));
        $clean = match ($provider) {
            'doppler' => ['token' => $value('token')],
            'onepassword' => ['host' => rtrim($value('host'), '/'), 'token' => $value('token'), 'vault' => $value('vault'), 'item' => $value('item')],
            'aws' => ['region' => $value('region'), 'access_key' => $value('access_key'), 'secret_key' => $value('secret_key'), 'secret_id' => $value('secret_id')],
            default => throw new AccountRuleViolation('provider', __('Choose Doppler, 1Password Connect or AWS Secrets Manager.')),
        };
        if (in_array('', $clean, true)) {
            throw new AccountRuleViolation('provider', __('Fill in every field for :provider.', ['provider' => SecretSync::PROVIDERS[$provider]]));
        }
        $sync = new SecretSync;
        $sync->forceFill(['environment_id' => $environment->id, 'created_by' => $actor->id, 'provider' => $provider, 'name' => mb_substr(trim($name) ?: SecretSync::PROVIDERS[$provider], 0, 80), 'settings' => $clean])->save();
        RunSecretSync::dispatch($sync->id)->afterCommit();

        return $sync;
    }
}
