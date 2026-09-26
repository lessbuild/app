<?php

namespace App\Modules\Deployer\Services;

use App\Modules\Deployer\Support\PublicDnsResolver;
use App\Modules\Deployer\Support\PublicIpAddress;
use Throwable;

final class AlertWebhookTargetResolver
{
    public function __construct(private readonly PublicDnsResolver $dns) {}

    /**
     * Validate a public HTTPS destination and capture the exact public address for the later pinned request.
     *
     * @return array{endpoint: string, host: string|null, port: int|null, address: string|null, error: string|null}
     */
    public function resolve(string $endpoint): array
    {
        $parts = parse_url($endpoint);
        if (! is_array($parts)) {
            return $this->failure($endpoint, 'invalid_endpoint');
        }
        $host = $parts['host'] ?? null;
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $port = $parts['port'] ?? 443;
        if ($scheme !== 'https') {
            return $this->failure($endpoint, 'https_required');
        }
        if (! is_string($host) || $host === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || ! is_int($port) || $port < 1 || $port > 65535) {
            return $this->failure($endpoint, 'invalid_endpoint');
        }

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        $host = strtolower($host);
        if (! filter_var($host, FILTER_VALIDATE_IP) && (preg_match('/[^\x21-\x7e]/', $host)
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false)) {
            return $this->failure($endpoint, 'invalid_endpoint');
        }

        try {
            $addresses = filter_var($host, FILTER_VALIDATE_IP)
                ? [$host]
                : $this->dns->addresses($host);
        } catch (Throwable) {
            return $this->failure($endpoint, 'dns_unavailable', $host, $port);
        }
        if ($addresses === []) {
            return $this->failure($endpoint, 'dns_unavailable', $host, $port);
        }
        foreach ($addresses as $address) {
            if (! is_string($address) || ! PublicIpAddress::isValid($address)) {
                return $this->failure($endpoint, 'non_public_address', $host, $port);
            }
        }

        return [
            'endpoint' => $endpoint,
            'host' => $host,
            'port' => $port,
            'address' => $addresses[0],
            'error' => null,
        ];
    }

    /** @return array{endpoint: string, host: string|null, port: int|null, address: string|null, error: string} */
    private function failure(string $endpoint, string $code, ?string $host = null, ?int $port = null): array
    {
        return ['endpoint' => $endpoint, 'host' => $host, 'port' => $port, 'address' => null, 'error' => $code];
    }
}
