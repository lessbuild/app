<?php

namespace App\Modules\Deployer\Actions\Provider;

use App\Modules\Deployer\Exceptions\ProviderOperationException;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\Provider;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\Entitlements;
use Illuminate\Support\Arr;

class UpdateProviderAction
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Apply editable provider settings while preserving credential omission and health-reset semantics.
     *
     * @param  array<string, mixed>  $attributes  Validated and normalized provider settings.
     *
     * @throws ProviderOperationException When attached resources prevent changing the provider type.
     */
    public function handle(User $actor, Provider $provider, array $attributes): void
    {
        /** @var Organization $organization */
        $organization = $actor->currentOrganization;
        if ((bool) $attributes['connection_monitoring_enabled']) {
            $this->entitlements->enforce($organization, 'monitoring');
        }

        $providerType = str((string) $attributes['provider'])->lower()->toString();
        if ($provider->provider !== $providerType && $provider->hasAttachedResources()) {
            throw new ProviderOperationException('provider', __('A provider type cannot be changed while resources are attached.'), withInput: true);
        }

        $validated = Arr::except($attributes, ['token']);
        $credentialChanged = filled($attributes['token'] ?? null) || $provider->provider !== $providerType;
        if (filled($attributes['token'] ?? null)) {
            $validated['token'] = $attributes['token'];
        }

        $provider->update(array_merge($validated, [
            'provider' => $providerType,
        ]));

        if ($credentialChanged) {
            $provider->resetConnectionHealth();
        }
    }
}
