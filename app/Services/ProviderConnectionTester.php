<?php

namespace App\Services;

use App\Models\Provider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class ProviderConnectionTester
{
    /** @return array{successful: bool, message: string} */
    public function test(Provider $provider): array
    {
        $label = $this->label($provider);

        if (! is_string($provider->token) || $provider->token === '') {
            return [
                'successful' => false,
                'message' => __('Connection failed. This provider has no credential.'),
            ];
        }

        try {
            $response = $this->request($provider);
        } catch (ConnectionException) {
            return [
                'successful' => false,
                'message' => __('Could not reach :provider. Try again later.', ['provider' => $label]),
            ];
        }

        if ($response === null) {
            return [
                'successful' => false,
                'message' => __('Connection checks are not supported for this provider type.'),
            ];
        }

        if ($response->successful()) {
            return [
                'successful' => true,
                'message' => __('Connection successful. :provider accepted this credential.', ['provider' => $label]),
            ];
        }

        return [
            'successful' => false,
            'message' => __('Connection failed. :provider returned HTTP :status. Verify the credential and its permissions.', [
                'provider' => $label,
                'status' => $response->status(),
            ]),
        ];
    }

    private function request(Provider $provider): ?Response
    {
        $request = Http::acceptJson()
            ->connectTimeout(3)
            ->timeout(8)
            ->withHeaders(['User-Agent' => 'Lessbuild']);

        return match ($provider->provider) {
            Provider::TYPE_GITHUB => $request
                ->withToken($provider->token)
                ->withHeader('X-GitHub-Api-Version', '2026-03-10')
                ->get('https://api.github.com/user'),
            Provider::TYPE_GITLAB => $request
                ->withHeader('PRIVATE-TOKEN', $provider->token)
                ->get('https://gitlab.com/api/v4/user'),
            Provider::TYPE_BITBUCKET => $request
                ->withToken($provider->token)
                ->get('https://api.bitbucket.org/2.0/user'),
            Provider::TYPE_DIGITALOCEAN => $request
                ->withToken($provider->token)
                ->get('https://api.digitalocean.com/v2/account'),
            default => null,
        };
    }

    private function label(Provider $provider): string
    {
        return match ($provider->provider) {
            Provider::TYPE_GITHUB => 'GitHub',
            Provider::TYPE_GITLAB => 'GitLab',
            Provider::TYPE_BITBUCKET => 'Bitbucket',
            Provider::TYPE_DIGITALOCEAN => 'DigitalOcean',
            default => str($provider->provider)->headline()->toString(),
        };
    }
}
