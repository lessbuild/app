<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Enums\AlertDestinationType;
use App\Enums\ProviderType;
use App\Models\Provider;
use App\Services\Monitoring\PublicWebhookTarget;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** Asks a provider's API whether it accepts the stored credential, using the least-privileged read each API offers. */
final class ProviderConnectionTester
{
    /**
     * Create a new ProviderConnectionTester instance.
     *
     * @param  PublicWebhookTarget  $targets  Checks a self-hosted GitLab's address is public before it's called.
     */
    public function __construct(private readonly PublicWebhookTarget $targets) {}

    /**
     * Ask the provider whether it accepts the credential and returns the outcome with a message safe to show. GitHub
     * App providers are tested by requesting an installation token.
     *
     * @param  Provider  $provider
     * @return array{successful: bool, message: string, http_status: int|null}
     */
    public function test(Provider $provider): array
    {
        $label = $provider->type->label();
        if ($provider->token === '') {
            return ['successful' => false, 'message' => __('Connection failed. This provider has no credential.'), 'http_status' => null];
        }

        if ($provider->isGitHubApp()) {
            try {
                app(\App\Services\Deploy\GitHubApp::class)->installationToken((string) $provider->external_id);
            } catch (Throwable) {
                return ['successful' => false, 'message' => __('Connection failed. GitHub didn’t issue a token for this App installation; it may have been uninstalled.'), 'http_status' => null];
            }

            return ['successful' => true, 'message' => __('Connection successful. The GitHub App installation is active.'), 'http_status' => 201];
        }

        try {
            $response = $this->request($provider);
        } catch (Throwable) {
            return ['successful' => false, 'message' => __('Could not reach :provider. Try again later.', ['provider' => $label]), 'http_status' => null];
        }

        if ($response->successful()) {
            return ['successful' => true, 'message' => __('Connection successful. :provider accepted this credential.', ['provider' => $label]), 'http_status' => $response->status()];
        }

        return [
            'successful' => false,
            'message' => __('Connection failed. :provider returned HTTP :status. Check the credential and its permissions.', ['provider' => $label, 'status' => $response->status()]),
            'http_status' => $response->status(),
        ];
    }

    /**
     * Get the read-only API call used to test each provider type; cloud providers use a listing that scoped tokens can
     * make.
     *
     * @param  ProviderType  $type
     * @return string
     */
    public function endpoint(ProviderType $type): string
    {
        return match ($type) {
            ProviderType::GitHub => 'https://api.github.com/user',
            ProviderType::GitLab => 'https://gitlab.com/api/v4/user',
            ProviderType::Bitbucket => 'https://api.bitbucket.org/2.0/user',
            // Scoped deployment credentials don't need access to account details.
            ProviderType::DigitalOcean => 'https://api.digitalocean.com/v2/droplets?per_page=1',
            ProviderType::Hetzner => 'https://api.hetzner.cloud/v1/servers?per_page=1',
            ProviderType::Vultr => 'https://api.vultr.com/v2/account',
            ProviderType::Cloudflare => rtrim((string) config('infrastructure.cloudflare_api_url'), '/').'/user/tokens/verify',
        };
    }

    /**
     * Send the test request with the credential in the header the provider expects.
     *
     * @param  Provider  $provider
     * @return Response
     */
    private function request(Provider $provider): Response
    {
        $request = Http::acceptJson()->connectTimeout(3)->timeout(8)->withHeaders(['User-Agent' => (string) config('app.name')]);

        if ($provider->type === ProviderType::GitLab && $provider->base_url !== null) {
            // A self-hosted GitLab is the customer's address: only a public one, connected to at the address checked.
            $url = $provider->gitLabApiBase().'/user';
            $target = $this->targets->resolve($url, AlertDestinationType::Webhook);
            if ($target['error'] !== null || $target['host'] === null || $target['address'] === null) {
                throw new RuntimeException('The GitLab address isn’t a public HTTPS address.');
            }
            $pinned = str_contains($target['address'], ':') ? '['.$target['address'].']' : $target['address'];

            return $request->withoutRedirecting()->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$target['host']}:443:{$pinned}"]]])
                ->withHeader('PRIVATE-TOKEN', $provider->token)->get($url);
        }

        return match ($provider->type) {
            ProviderType::GitLab => $request->withHeader('PRIVATE-TOKEN', $provider->token)->get($this->endpoint($provider->type)),
            ProviderType::GitHub => $this->bearer($request, $provider)->withHeader('X-GitHub-Api-Version', '2026-03-10')->get($this->endpoint($provider->type)),
            default => $this->bearer($request, $provider)->get($this->endpoint($provider->type)),
        };
    }

    /**
     * Add the credential as a bearer token.
     *
     * @param  PendingRequest  $request
     * @param  Provider  $provider
     * @return PendingRequest
     */
    private function bearer(PendingRequest $request, Provider $provider): PendingRequest
    {
        return $request->withToken($provider->token);
    }
}
