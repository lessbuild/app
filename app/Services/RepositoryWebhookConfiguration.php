<?php

namespace App\Services;

use App\Exceptions\RepositoryWebhookConfigurationException;
use App\Models\Provider;
use App\Models\Repository;

class RepositoryWebhookConfiguration
{
    /**
     * Add the automatic GitHub App webhook settings required for a new repository.
     *
     * @param  Provider  $provider  Tenant-scoped source provider selected for the repository.
     * @param  array<string, mixed>  $attributes  Validated repository attributes.
     * @return array<string, mixed> Attributes ready for repository persistence.
     *
     * @throws RepositoryWebhookConfigurationException When the required application secret is absent.
     */
    public function forCreation(Provider $provider, array $attributes): array
    {
        if (! $provider->isGitHubApp()) {
            return $attributes;
        }

        return [
            ...$attributes,
            'webhook_enabled' => true,
            'webhook_secret' => $this->secret(),
        ];
    }

    /**
     * Apply the webhook transition rules when a repository changes source providers.
     *
     * @param  Repository  $repository  Existing repository whose prior provider state is considered.
     * @param  Provider  $provider  Tenant-scoped provider selected for the update.
     * @param  array<string, mixed>  $attributes  Validated repository attributes.
     * @return array<string, mixed> Attributes ready for transaction-time persistence.
     *
     * @throws RepositoryWebhookConfigurationException When a selected GitHub App has no webhook secret.
     */
    public function forUpdate(Repository $repository, Provider $provider, array $attributes): array
    {
        if ($provider->isGitHubApp()) {
            return [
                ...$attributes,
                'webhook_enabled' => true,
                'webhook_secret' => $this->secret(),
            ];
        }

        if ($repository->provider?->isGitHubApp()) {
            return [
                ...$attributes,
                'webhook_enabled' => false,
                'webhook_secret' => null,
            ];
        }

        return $attributes;
    }

    /**
     * Return the application webhook secret or identify unavailable integration configuration.
     *
     * @return string The configured GitHub App webhook secret.
     *
     * @throws RepositoryWebhookConfigurationException When no secret is configured.
     */
    private function secret(): string
    {
        $secret = config('github-app.webhook_secret');
        if (! filled($secret)) {
            throw new RepositoryWebhookConfigurationException;
        }

        return (string) $secret;
    }
}
