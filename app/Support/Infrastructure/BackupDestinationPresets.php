<?php

declare(strict_types=1);

namespace App\Support\Infrastructure;

/** The S3-compatible services backups can go to, with hints for the form and the endpoints we can fill in from a region. */
final class BackupDestinationPresets
{
    /** @return array<string, array{name: string, description: string, endpoint: string, region: string}> */
    public static function all(): array
    {
        return [
            'digitalocean_spaces' => ['name' => 'DigitalOcean Spaces', 'description' => __('Use a Spaces access key and secret, not a DigitalOcean API token.'), 'endpoint' => 'https://<region>.digitaloceanspaces.com', 'region' => 'ams3, fra1, lon1, nyc3, sfo3, sgp1…'],
            'amazon_s3' => ['name' => 'Amazon S3', 'description' => __('Use an IAM access key that can read and write the bucket.'), 'endpoint' => 'https://s3.<region>.amazonaws.com', 'region' => 'us-east-1 or your bucket’s region'],
            'cloudflare_r2' => ['name' => 'Cloudflare R2', 'description' => __('Use an R2 API token and your account’s S3 endpoint.'), 'endpoint' => 'https://<account-id>.r2.cloudflarestorage.com', 'region' => 'auto'],
            's3_compatible' => ['name' => __('Other S3-compatible storage'), 'description' => __('MinIO, Wasabi, Backblaze B2 and other S3-compatible services.'), 'endpoint' => 'https://storage.example.com', 'region' => __('The region your provider asks for')],
        ];
    }

    /** The endpoint for Spaces and S3 when only the region was given. */
    public static function endpoint(string $provider, string $region, ?string $endpoint): ?string
    {
        if (filled($endpoint) || preg_match('/\A[a-z0-9][a-z0-9-]{0,31}\z/iD', $region) !== 1) {
            return $endpoint;
        }

        return match ($provider) {
            'digitalocean_spaces' => "https://{$region}.digitaloceanspaces.com",
            'amazon_s3' => $region === 'us-east-1' ? 'https://s3.amazonaws.com' : "https://s3.{$region}.amazonaws.com",
            default => $endpoint,
        };
    }
}
