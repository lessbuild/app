<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Enums\ProviderType;
use App\Models\Provider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Asks a provider's API whether it accepts the stored credential, using the least-privileged read each API offers. */
final class ProviderConnectionTester
{
    /** @return array{successful: bool, message: string, http_status: int|null} */
    public function test(Provider $provider): array
    {
        $label = $provider->type->label();
        if ($provider->token === '') {
            return ['successful' => false, 'message' => __('Connection failed. This provider has no credential.'), 'http_status' => null];
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

    private function request(Provider $provider): Response
    {
        $request = Http::acceptJson()->connectTimeout(3)->timeout(8)->withHeaders(['User-Agent' => (string) config('app.name')]);

        return match ($provider->type) {
            ProviderType::GitLab => $request->withHeader('PRIVATE-TOKEN', $provider->token)->get($this->endpoint($provider->type)),
            ProviderType::GitHub => $this->bearer($request, $provider)->withHeader('X-GitHub-Api-Version', '2026-03-10')->get($this->endpoint($provider->type)),
            default => $this->bearer($request, $provider)->get($this->endpoint($provider->type)),
        };
    }

    private function bearer(PendingRequest $request, Provider $provider): PendingRequest
    {
        return $request->withToken($provider->token);
    }
}
