<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\SecretSync;
use App\Services\Monitoring\PublicHttpTarget;
use App\Support\AwsSignature;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Reads secrets from the password managers environments sync from, with the customer's own credentials. */
final class SecretSources
{
    /**
     * Create a new SecretSources instance.
     *
     * @param  PublicHttpTarget  $targets  Checks a 1Password Connect server's address is public.
     */
    public function __construct(private readonly PublicHttpTarget $targets) {}

    /**
     * Fetch the source's secrets as key/value pairs. Throws with the provider's own message when it refuses.
     *
     * @param  SecretSync  $sync
     * @return array<string, string>
     */
    public function fetch(SecretSync $sync): array
    {
        $settings = $sync->settings;

        return match ($sync->provider) {
            'doppler' => $this->doppler($settings),
            'onepassword' => $this->onePassword($settings),
            'aws' => $this->aws($settings),
            default => throw new RuntimeException('Unknown secret source.'),
        };
    }

    /**
     * Download a Doppler config's secrets with a service token (it's scoped to one project and config).
     *
     * @param  array<string, string>  $settings
     * @return array<string, string>
     */
    private function doppler(array $settings): array
    {
        $response = Http::timeout(15)->withToken($settings['token'] ?? '')->acceptJson()->get('https://api.doppler.com/v3/configs/config/secrets/download', ['format' => 'json']);
        if ($response->failed() || ! is_array($response->json())) {
            throw new RuntimeException('Doppler answered HTTP '.$response->status().': '.(string) $response->json('messages.0', 'no details'));
        }

        return $this->strings((array) $response->json());
    }

    /**
     * Read one 1Password item's fields (label = value) from the customer's Connect server.
     *
     * @param  array<string, string>  $settings
     * @return array<string, string>
     */
    private function onePassword(array $settings): array
    {
        $host = rtrim($settings['host'] ?? '', '/');
        if ($this->targets->parse($host) === null || ! str_starts_with($host, 'https://')) {
            throw new RuntimeException('The Connect server needs a public https:// address.');
        }
        $response = Http::timeout(15)->withToken($settings['token'] ?? '')->acceptJson()->withoutRedirecting()
            ->get($host.'/v1/vaults/'.rawurlencode($settings['vault'] ?? '').'/items/'.rawurlencode($settings['item'] ?? ''));
        if ($response->failed()) {
            throw new RuntimeException('1Password Connect answered HTTP '.$response->status().': '.(string) $response->json('message', 'no details'));
        }
        $values = [];
        foreach ((array) $response->json('fields', []) as $field) {
            if (is_array($field) && is_string($field['label'] ?? null) && is_scalar($field['value'] ?? null) && ($field['label'] !== '')) {
                $values[$field['label']] = (string) $field['value'];
            }
        }

        return $values;
    }

    /**
     * Read a secret from AWS Secrets Manager whose value is a JSON object of key/value pairs.
     *
     * @param  array<string, string>  $settings
     * @return array<string, string>
     */
    private function aws(array $settings): array
    {
        $region = $settings['region'] ?? '';
        if (preg_match('/\A[a-z]{2}(-[a-z]+)+-\d\z/', $region) !== 1) {
            throw new RuntimeException('That isn’t an AWS region.');
        }
        $url = "https://secretsmanager.{$region}.amazonaws.com/";
        $body = (string) json_encode(['SecretId' => $settings['secret_id'] ?? '']);
        $headers = AwsSignature::headers($settings['access_key'] ?? '', $settings['secret_key'] ?? '', $region, 'secretsmanager', 'POST', $url, [
            'Content-Type' => 'application/x-amz-json-1.1', 'X-Amz-Target' => 'secretsmanager.GetSecretValue',
        ], $body);
        unset($headers['Host']);
        $response = Http::withHeaders([...$headers, 'User-Agent' => 'BuildPusher'])->withBody($body, 'application/x-amz-json-1.1')->timeout(15)->post($url);
        if ($response->failed()) {
            throw new RuntimeException('AWS answered HTTP '.$response->status().': '.(string) ($response->json('message') ?? $response->json('Message') ?? 'no details'));
        }
        $secret = json_decode((string) $response->json('SecretString'), true);
        if (! is_array($secret)) {
            throw new RuntimeException('The secret’s value isn’t a JSON object of key/value pairs.');
        }

        return $this->strings($secret);
    }

    /**
     * Keep the scalar values, as strings.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<string, string>
     */
    private function strings(array $values): array
    {
        $strings = [];
        foreach ($values as $key => $value) {
            if (is_scalar($value)) {
                $strings[(string) $key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
        }

        return $strings;
    }
}
