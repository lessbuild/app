<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Contracts\Infrastructure\ServerProvider;
use App\Data\Infrastructure\CloudServerData;
use App\Data\Infrastructure\CloudSshKeyData;
use App\Support\AwsSignature;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use stdClass;

/**
 * AWS Lightsail instances, with an IAM access key signed as AWS Signature Version 4. Lightsail keys and instances are
 * per region, so an instance's ID is "region/name", the SSH key is added by the launch script, and HTTPS (443) is
 * opened once the instance runs, since Lightsail only allows 22 and 80 at first.
 */
class Lightsail implements ServerProvider
{
    /** The region catalogue calls go to; bundles and blueprints are the same everywhere. */
    private const CATALOG_REGION = 'us-east-1';

    /**
     * The access key ID.
     *
     * @var string
     */
    private readonly string $accessKey;

    /**
     * The secret access key.
     *
     * @var string
     */
    private readonly string $secret;

    /**
     * Create a new Lightsail instance.
     *
     * @param  string  $token  "ACCESS_KEY_ID:SECRET_ACCESS_KEY" for an IAM user allowed Lightsail actions
     */
    public function __construct(string $token)
    {
        [$accessKey, $secret] = array_pad(explode(':', trim($token), 2), 2, '');
        $this->accessKey = $accessKey;
        $this->secret = $secret;
    }

    /**
     * Get the provider's name.
     *
     * @return string
     */
    public function name(): string
    {
        return 'AWS Lightsail';
    }

    /**
     * Keep the public key to add at launch: Lightsail keys are per region, so it isn't registered with AWS. The
     * "fingerprint" carries the key itself.
     *
     * @param  string  $name
     * @param  string  $publicKey
     * @return CloudSshKeyData
     */
    public function createSshKey(string $name, string $publicKey): CloudSshKeyData
    {
        return new CloudSshKeyData('lightsail:'.base64_encode(trim($publicKey)), false);
    }

    /**
     * Nothing to remove at AWS, since keys aren't registered there.
     *
     * @param  string  $fingerprint
     * @return bool
     */
    public function deleteSshKey(string $fingerprint): bool
    {
        return true;
    }

    /**
     * Create an instance in an availability zone (the "region" choice), with the SSH keys added before the
     * provisioning script runs.
     *
     * @param  array{name: string, region: string, size: string, image: int|string, ssh_keys?: list<int|string>, user_data?: string|null}  $parameters
     * @return CloudServerData
     */
    public function createServer(array $parameters): CloudServerData
    {
        $zone = $parameters['region'];
        $region = $this->regionOf($zone);
        $name = substr((string) preg_replace('/[^A-Za-z0-9_.-]+/', '-', $parameters['name']), 0, 255);
        $keys = '';
        foreach ($parameters['ssh_keys'] ?? [] as $key) {
            $material = base64_decode(substr((string) $key, strlen('lightsail:')), true);
            if (is_string($material) && preg_match('/\A[a-z0-9-]+ [A-Za-z0-9+\/=]+( [^\n]*)?\z/', $material) === 1) {
                $keys .= 'echo '.escapeshellarg($material)." >> /root/.ssh/authorized_keys\n";
            }
        }
        $script = (string) ($parameters['user_data'] ?? '');
        $launch = "#!/bin/bash\nmkdir -p /root/.ssh && chmod 700 /root/.ssh\n{$keys}chmod 600 /root/.ssh/authorized_keys\n".(str_starts_with($script, '#!') ? substr($script, (int) strpos($script, "\n") + 1) : $script);
        $response = $this->call($region, 'CreateInstances', [
            'instanceNames' => [$name], 'availabilityZone' => $zone, 'blueprintId' => (string) $parameters['image'],
            'bundleId' => $parameters['size'], 'userData' => $launch,
        ]);
        if (! $response->successful()) {
            throw $this->exception($response, 'instance creation');
        }

        return new CloudServerData(
            identifier: "{$region}/{$name}", name: $name, region: $zone, size: $parameters['size'], image: (string) $parameters['image'],
            publicIp: null, privateIp: null, providerStatus: 'pending', readiness: CloudServerData::READINESS_NOT_READY,
        );
    }

    /**
     * Look up an instance; once it runs, make sure HTTPS is open.
     *
     * @param  int|string  $identifier  "region/name"
     * @return CloudServerData
     */
    public function server(int|string $identifier): CloudServerData
    {
        [$region, $name] = $this->split((string) $identifier);
        $response = $this->call($region, 'GetInstance', ['instanceName' => $name]);
        if (! $response->successful()) {
            throw $this->exception($response, 'instance lookup');
        }
        $instance = (array) $response->json('instance', []);
        $status = is_string($instance['state']['name'] ?? null) ? $instance['state']['name'] : null;
        $public = is_string($instance['publicIpAddress'] ?? null) ? $instance['publicIpAddress'] : null;
        if ($status === 'running') {
            $this->call($region, 'OpenInstancePublicPorts', ['instanceName' => $name, 'portInfo' => ['fromPort' => 443, 'toPort' => 443, 'protocol' => 'tcp']]);
        }

        return new CloudServerData(
            identifier: "{$region}/{$name}", name: $name,
            region: (string) ($instance['location']['availabilityZone'] ?? $region), size: (string) ($instance['bundleId'] ?? ''), image: (string) ($instance['blueprintId'] ?? ''),
            publicIp: $public, privateIp: is_string($instance['privateIpAddress'] ?? null) ? $instance['privateIpAddress'] : null, providerStatus: $status,
            readiness: $status === null ? CloudServerData::READINESS_UNKNOWN
                : ($status === 'running' && $public !== null ? CloudServerData::READINESS_READY : CloudServerData::READINESS_NOT_READY),
        );
    }

    /**
     * Delete an instance; a missing one counts as deleted.
     *
     * @param  int|string  $identifier
     * @return bool
     */
    public function deleteServer(int|string $identifier): bool
    {
        [$region, $name] = $this->split((string) $identifier);
        $response = $this->call($region, 'DeleteInstance', ['instanceName' => $name]);

        return $response->successful() || str_contains((string) $response->json('__type', ''), 'NotFound');
    }

    /**
     * List the available availability zones in every region, which is what an instance is created in.
     *
     * @return list<array<string, mixed>>
     */
    public function regions(): array
    {
        $response = $this->call(self::CATALOG_REGION, 'GetRegions', ['includeAvailabilityZones' => true]);
        if (! $response->successful()) {
            throw $this->exception($response, 'region listing');
        }
        $zones = [];
        foreach ((array) $response->json('regions', []) as $region) {
            foreach ((array) (is_array($region) ? ($region['availabilityZones'] ?? []) : []) as $zone) {
                if (is_array($zone) && ($zone['state'] ?? '') === 'available') {
                    $zones[] = ['id' => (string) ($zone['zoneName'] ?? ''), 'label' => ((string) ($region['displayName'] ?? $region['name'] ?? '')).' ('.($zone['zoneName'] ?? '').')'];
                }
            }
        }

        return $zones;
    }

    /**
     * List the active Linux bundles (instance plans).
     *
     * @return list<array<string, mixed>>
     */
    public function sizes(): array
    {
        $response = $this->call(self::CATALOG_REGION, 'GetBundles', ['includeInactive' => false]);
        if (! $response->successful()) {
            throw $this->exception($response, 'bundle listing');
        }

        return array_values(array_filter((array) $response->json('bundles', []), fn (mixed $bundle): bool => is_array($bundle)
            && ($bundle['isActive'] ?? false) && in_array('LINUX_UNIX', (array) ($bundle['supportedPlatforms'] ?? []), true)));
    }

    /**
     * List the operating-system blueprints for Linux.
     *
     * @return list<array<string, mixed>>
     */
    public function images(): array
    {
        $response = $this->call(self::CATALOG_REGION, 'GetBlueprints', ['includeInactive' => false]);
        if (! $response->successful()) {
            throw $this->exception($response, 'blueprint listing');
        }

        return array_values(array_filter((array) $response->json('blueprints', []), fn (mixed $blueprint): bool => is_array($blueprint)
            && ($blueprint['type'] ?? '') === 'os' && ($blueprint['platform'] ?? '') === 'LINUX_UNIX' && ($blueprint['isActive'] ?? false)));
    }

    /**
     * Make a signed read-only call that checks the key works, for the provider's connection test.
     *
     * @return Response
     */
    public function ping(): Response
    {
        return $this->call(self::CATALOG_REGION, 'GetRegions', []);
    }

    /**
     * Call a Lightsail action in a region.
     *
     * @param  string  $region
     * @param  string  $action  e.g. GetInstance
     * @param  array<string, mixed>  $payload
     * @return Response
     */
    private function call(string $region, string $action, array $payload): Response
    {
        if (preg_match('/\A[a-z]{2}(-[a-z]+)+-\d\z/', $region) !== 1) {
            throw new RuntimeException('That isn’t an AWS region.');
        }
        $url = "https://lightsail.{$region}.amazonaws.com/";
        $body = json_encode($payload === [] ? new stdClass : $payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $headers = AwsSignature::headers($this->accessKey, $this->secret, $region, 'lightsail', 'POST', $url, [
            'Content-Type' => 'application/x-amz-json-1.1', 'X-Amz-Target' => 'Lightsail_20161128.'.$action,
        ], $body);
        unset($headers['Host']);

        return Http::withHeaders([...$headers, 'User-Agent' => 'BuildPusher'])->withBody($body, 'application/x-amz-json-1.1')
            ->connectTimeout(5)->timeout(20)->post($url);
    }

    /**
     * Get the region of an availability zone (eu-west-2a → eu-west-2).
     *
     * @param  string  $zone
     * @return string
     */
    private function regionOf(string $zone): string
    {
        return (string) preg_replace('/[a-z]\z/', '', $zone);
    }

    /**
     * Split an instance ID into its region and name.
     *
     * @param  string  $identifier
     * @return array{0: string, 1: string}
     */
    private function split(string $identifier): array
    {
        [$region, $name] = array_pad(explode('/', $identifier, 2), 2, '');

        return [$region, $name];
    }

    /**
     * Describe a failed Lightsail operation without exposing its response.
     *
     * @param  Response  $response
     * @param  string  $operation
     * @return RuntimeException
     */
    private function exception(Response $response, string $operation): RuntimeException
    {
        return new RuntimeException("Lightsail {$operation} failed with HTTP {$response->status()}.");
    }
}
