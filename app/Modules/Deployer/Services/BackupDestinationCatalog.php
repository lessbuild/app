<?php

namespace App\Modules\Deployer\Services;

use App\Modules\Deployer\Data\BackupDestinationPreset;
use InvalidArgumentException;

class BackupDestinationCatalog
{
    public const DIGITALOCEAN_SPACES = 'digitalocean_spaces';

    public const AMAZON_S3 = 'amazon_s3';

    public const CLOUDFLARE_R2 = 'cloudflare_r2';

    public const S3_COMPATIBLE = 's3_compatible';

    /**
     * Return the supported setup presets used by the destination form.
     *
     * @return array<string, BackupDestinationPreset> Presets keyed by the form-only provider value.
     */
    public function all(): array
    {
        return [
            self::DIGITALOCEAN_SPACES => new BackupDestinationPreset(
                key: self::DIGITALOCEAN_SPACES,
                name: 'DigitalOcean Spaces',
                description: 'Use a Spaces access key and secret. A DigitalOcean control-plane token is different.',
                endpointHint: 'https://<region>.digitaloceanspaces.com',
                regionHint: 'lon1, nyc3, ams3, fra1, sfo3, sgp1 or another Spaces region',
                documentationUrl: 'https://docs.digitalocean.com/products/spaces/how-to/manage-access/',
            ),
            self::AMAZON_S3 => new BackupDestinationPreset(
                key: self::AMAZON_S3,
                name: 'Amazon S3',
                description: 'Use an IAM access key with access to the selected bucket.',
                endpointHint: 'https://s3.<region>.amazonaws.com',
                regionHint: 'us-east-1 or your bucket region',
            ),
            self::CLOUDFLARE_R2 => new BackupDestinationPreset(
                key: self::CLOUDFLARE_R2,
                name: 'Cloudflare R2',
                description: 'Use an R2 API token and your account-specific S3 endpoint.',
                endpointHint: 'https://<account-id>.r2.cloudflarestorage.com',
                regionHint: 'auto',
            ),
            self::S3_COMPATIBLE => new BackupDestinationPreset(
                key: self::S3_COMPATIBLE,
                name: 'Other S3-compatible storage',
                description: 'Use this for MinIO, Wasabi, Backblaze or another S3-compatible service.',
                endpointHint: 'https://storage.example.com',
                regionHint: 'The region required by your provider',
            ),
        ];
    }

    /**
     * Return the form values accepted by request validation.
     *
     * @return list<string> Form-only provider preset keys.
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /**
     * Resolve a preset or fail closed when an action receives an unsupported form value.
     *
     * @throws InvalidArgumentException When the preset key is not configured.
     */
    public function for(string $key): BackupDestinationPreset
    {
        return $this->all()[$key] ?? throw new InvalidArgumentException('The backup destination preset is not configured.');
    }

    /**
     * Infer a form preset for an existing endpoint without persisting a provider type.
     */
    public function forEndpoint(?string $endpoint): string
    {
        $host = parse_url((string) $endpoint, PHP_URL_HOST);
        if (! is_string($host)) {
            return self::S3_COMPATIBLE;
        }

        $host = strtolower($host);
        if (str_ends_with($host, '.digitaloceanspaces.com')) {
            return self::DIGITALOCEAN_SPACES;
        }
        if (str_ends_with($host, '.amazonaws.com') || $host === 's3.amazonaws.com') {
            return self::AMAZON_S3;
        }
        if (str_ends_with($host, '.r2.cloudflarestorage.com')) {
            return self::CLOUDFLARE_R2;
        }

        return self::S3_COMPATIBLE;
    }

    /**
     * Apply safe provider defaults before validation while retaining the form-only preset.
     *
     * @param  array<string, mixed>  $attributes  Untrusted request input at the HTTP boundary.
     * @return array<string, mixed> Input with a default preset and derivable endpoint applied.
     */
    public function prepare(array $attributes): array
    {
        $attributes['storage_provider'] = filled($attributes['storage_provider'] ?? null)
            ? (string) $attributes['storage_provider']
            : self::S3_COMPATIBLE;

        $provider = $attributes['storage_provider'];
        $region = trim((string) ($attributes['region'] ?? ''));
        if (blank($attributes['endpoint'] ?? null)
            && preg_match('/\A[a-z0-9][a-z0-9-]{0,31}\z/iD', $region) === 1) {
            if ($provider === self::DIGITALOCEAN_SPACES) {
                $attributes['endpoint'] = "https://{$region}.digitaloceanspaces.com";
            } elseif ($provider === self::AMAZON_S3) {
                $attributes['endpoint'] = $region === 'us-east-1'
                    ? 'https://s3.amazonaws.com'
                    : "https://s3.{$region}.amazonaws.com";
            }
        }

        return $attributes;
    }

    /**
     * Remove form-only metadata before an action persists the destination.
     *
     * @param  array<string, mixed>  $attributes  Validated destination values.
     * @return array<string, mixed> Persistable destination values.
     */
    public function normalize(array $attributes): array
    {
        $attributes = $this->prepare($attributes);
        $this->for((string) $attributes['storage_provider']);
        unset($attributes['storage_provider']);

        return $attributes;
    }
}
