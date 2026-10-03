<?php

declare(strict_types=1);

namespace App\Data\Telemetry;

use App\Enums\IngestSource;
use App\Services\Monitoring\TelemetryRedactor;

final readonly class ReleaseIdentity
{
    /**
     * Create a new ReleaseIdentity instance.
     *
     * Use `from()` or `fromEvent()`, which validate the labels first.
     *
     * @param  string  $version  The release version, such as a tag or commit.
     * @param  ?string  $service  The service the release is of, when the telemetry names one.
     * @param  ?string  $namespace  The service namespace, for telemetry that groups services.
     */
    private function __construct(public string $version, public ?string $service, public ?string $namespace) {}

    /**
     * Build a release identity from raw labels, or return null when the version is missing or any label is too long,
     * has control characters, or was redacted.
     *
     * @param  mixed  $version
     * @param  mixed  $service
     * @param  mixed  $namespace
     * @return ReleaseIdentity|null
     */
    public static function from(mixed $version, mixed $service = null, mixed $namespace = null): ?self
    {
        if (! self::validLabel($version, 128) || ! self::validLabel($service, 100, true) || ! self::validLabel($namespace, 100, true)) {
            return null;
        }

        return new self(trim($version), is_string($service) && trim($service) !== '' ? trim($service) : null, is_string($namespace) && trim($namespace) !== '' ? trim($namespace) : null);
    }

    /**
     * Read the release an ingested event belongs to from `service.version`, `service.name` and `service.namespace` in
     * its resource attributes (OTLP) or attributes (JSON; the event's own `service` wins there).
     *
     * @param  array<string, mixed>  $event
     * @param  IngestSource  $source
     * @return ReleaseIdentity|null
     */
    public static function fromEvent(array $event, IngestSource $source): ?self
    {
        if ($source !== IngestSource::Json) {
            $attributes = $event['payload']['resource_attributes'] ?? [];

            return is_array($attributes)
                ? self::from($attributes['service.version'] ?? null, $attributes['service.name'] ?? null, $attributes['service.namespace'] ?? null)
                : null;
        }

        $attributes = $event['attributes'] ?? [];

        return is_array($attributes)
            ? self::from($attributes['service.version'] ?? null, $event['service'] ?? $attributes['service.name'] ?? null, $attributes['service.namespace'] ?? null)
            : null;
    }

    /**
     * Hash the namespace and service together into a fixed-length key, used in unique indexes where the labels
     * themselves could be too long.
     *
     * @return string
     */
    public function serviceHash(): string
    {
        return hash('sha256', json_encode([$this->namespace, $this->service], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Hash the version into a fixed-length key, for the same reason.
     *
     * @return string
     */
    public function versionHash(): string
    {
        return hash('sha256', $this->version);
    }

    /**
     * Determine whether a label is safe to store: within the length limit, not blank unless optional, not a redaction
     * placeholder, and free of control characters.
     *
     * @param  mixed  $value
     * @param  int  $limit
     * @param  bool  $optional
     * @return bool
     */
    private static function validLabel(mixed $value, int $limit, bool $optional = false): bool
    {
        if ($value === null) {
            return $optional;
        }

        return is_string($value) && mb_strlen($value) <= $limit
            && ($optional || trim($value) !== '')
            && ! str_contains($value, TelemetryRedactor::REPLACEMENT)
            && preg_match('/[\x00-\x1f\x7f]/u', $value) === 0;
    }
}
