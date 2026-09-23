<?php

namespace App\Modules\Monitor\Data\Telemetry;

use App\Modules\Monitor\Services\Telemetry\TelemetryRedactor;

final readonly class ReleaseIdentity
{
    private function __construct(public string $version, public ?string $service, public ?string $namespace) {}

    public static function from(mixed $version, mixed $service = null, mixed $namespace = null): ?self
    {
        if (! self::validLabel($version, 128) || ! self::validLabel($service, 100, true) || ! self::validLabel($namespace, 100, true)) {
            return null;
        }

        return new self(trim($version), is_string($service) && trim($service) !== '' ? trim($service) : null, is_string($namespace) && trim($namespace) !== '' ? trim($namespace) : null);
    }

    /** @param array<string, mixed> $event */
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

    public function serviceHash(): string
    {
        return hash('sha256', json_encode([$this->namespace, $this->service], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function versionHash(): string
    {
        return hash('sha256', $this->version);
    }

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
