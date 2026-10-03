<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Contracts\Infrastructure\ServerProvider;
use App\Data\Infrastructure\CloudServerData;
use App\Data\Infrastructure\CloudSshKeyData;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OVHcloud Public Cloud instances, with an API application's keys and a consumer key allowed /cloud/project/*
 * (every call is signed). Instances go in one Public Cloud project, billed hourly, with the region's Ubuntu image
 * and flavor looked up at launch; the SSH key and provisioning script go in as cloud-init user data. Sizes are
 * priced from OVHcloud's public catalogue, in euros.
 */
class Ovh implements ServerProvider
{
    /**
     * The API endpoints a credential can use.
     *
     * @var array<string, string>
     */
    private const array ENDPOINTS = ['eu' => 'https://eu.api.ovh.com/1.0', 'ca' => 'https://ca.api.ovh.com/1.0'];

    /**
     * The Ubuntu releases offered, by the image name OVHcloud lists them under.
     *
     * @var array<string, string>
     */
    private const array IMAGES = ['ubuntu-24.04' => 'Ubuntu 24.04', 'ubuntu-22.04' => 'Ubuntu 22.04'];

    /**
     * The API endpoint's base URL.
     *
     * @var string
     */
    private readonly string $base;

    /**
     * The application key.
     *
     * @var string
     */
    private readonly string $applicationKey;

    /**
     * The application secret.
     *
     * @var string
     */
    private readonly string $applicationSecret;

    /**
     * The consumer key.
     *
     * @var string
     */
    private readonly string $consumerKey;

    /**
     * The Public Cloud project instances go in.
     *
     * @var string
     */
    private readonly string $project;

    /**
     * Create a new Ovh instance.
     *
     * @param  string  $token  "ENDPOINT:APPLICATION_KEY:APPLICATION_SECRET:CONSUMER_KEY:PROJECT_ID", the endpoint eu or ca
     */
    public function __construct(string $token)
    {
        [$endpoint, $applicationKey, $applicationSecret, $consumerKey, $project] = array_pad(explode(':', trim($token), 5), 5, '');
        $this->base = self::ENDPOINTS[$endpoint] ?? self::ENDPOINTS['eu'];
        $this->applicationKey = $applicationKey;
        $this->applicationSecret = $applicationSecret;
        $this->consumerKey = $consumerKey;
        $this->project = $project;
    }

    /**
     * Determine whether a credential has the shape ENDPOINT:APPLICATION_KEY:APPLICATION_SECRET:CONSUMER_KEY:PROJECT_ID.
     *
     * @param  string  $token
     * @return bool
     */
    public static function validCredential(string $token): bool
    {
        return preg_match('/\A(eu|ca):[A-Za-z0-9]{8,64}:[A-Za-z0-9]{16,64}:[A-Za-z0-9]{16,64}:[0-9a-f]{32}\z/', trim($token)) === 1;
    }

    /**
     * Get the provider's name.
     *
     * @return string
     */
    public function name(): string
    {
        return 'OVHcloud';
    }

    /**
     * Keep the public key to add at launch; it isn't registered with OVHcloud. The "fingerprint" carries the key.
     *
     * @param  string  $name
     * @param  string  $publicKey
     * @return CloudSshKeyData
     */
    public function createSshKey(string $name, string $publicKey): CloudSshKeyData
    {
        return new CloudSshKeyData('ovh:'.base64_encode(trim($publicKey)), false);
    }

    /**
     * Nothing to remove at OVHcloud, since keys aren't registered there.
     *
     * @param  string  $fingerprint
     * @return bool
     */
    public function deleteSshKey(string $fingerprint): bool
    {
        return true;
    }

    /**
     * Launch an hourly-billed instance with the region's flavor and Ubuntu image.
     *
     * @param  array{name: string, region: string, size: string, image: int|string, ssh_keys?: list<int|string>, user_data?: string|null}  $parameters
     * @return CloudServerData
     */
    public function createServer(array $parameters): CloudServerData
    {
        $region = $parameters['region'];
        $flavor = $this->regionalId('flavor', $region, fn (array $flavor): bool => ($flavor['name'] ?? null) === $parameters['size']);
        $imageName = self::IMAGES[(string) $parameters['image']] ?? throw new RuntimeException('Choose Ubuntu 24.04 or 22.04.');
        $image = $this->regionalId('image', $region, fn (array $image): bool => ($image['name'] ?? null) === $imageName);
        $keys = '';
        foreach ($parameters['ssh_keys'] ?? [] as $key) {
            $material = base64_decode(substr((string) $key, strlen('ovh:')), true);
            if (is_string($material) && preg_match('/\A[a-z0-9-]+ [A-Za-z0-9+\/=]+( [^\n]*)?\z/', $material) === 1) {
                $keys .= 'echo '.escapeshellarg($material)." >> /root/.ssh/authorized_keys\n";
            }
        }
        $script = (string) ($parameters['user_data'] ?? '');
        $launch = "#!/bin/bash\nmkdir -p /root/.ssh && chmod 700 /root/.ssh\n{$keys}chmod 600 /root/.ssh/authorized_keys\n"
            ."sed -i 's/^#\\?PermitRootLogin .*/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config && (systemctl reload ssh || systemctl reload sshd || true)\n"
            .(str_starts_with($script, '#!') ? substr($script, (int) strpos($script, "\n") + 1) : $script);
        $name = substr((string) preg_replace('/[^A-Za-z0-9_.-]+/', '-', $parameters['name']), 0, 64);

        $instance = (array) $this->ok($this->call('POST', "/cloud/project/{$this->project}/instance", [
            'name' => $name, 'region' => $region, 'flavorId' => $flavor, 'imageId' => $image, 'userData' => $launch, 'monthlyBilling' => false,
        ]), 'instance creation')->json();
        $id = is_string($instance['id'] ?? null) ? $instance['id'] : throw new RuntimeException('OVHcloud returned no instance.');

        return new CloudServerData(
            identifier: $id, name: $name, region: $region, size: $parameters['size'], image: (string) $parameters['image'],
            publicIp: null, privateIp: null, providerStatus: 'BUILD', readiness: CloudServerData::READINESS_NOT_READY,
        );
    }

    /**
     * Look up an instance.
     *
     * @param  int|string  $identifier
     * @return CloudServerData
     */
    public function server(int|string $identifier): CloudServerData
    {
        $instance = (array) $this->ok($this->call('GET', "/cloud/project/{$this->project}/instance/".$this->id($identifier)), 'instance lookup')->json();
        $public = null;
        $private = null;
        foreach ((array) ($instance['ipAddresses'] ?? []) as $address) {
            if (is_array($address) && ($address['version'] ?? null) === 4 && is_string($address['ip'] ?? null)) {
                if (($address['type'] ?? null) === 'public') {
                    $public ??= $address['ip'];
                } else {
                    $private ??= $address['ip'];
                }
            }
        }
        $status = is_string($instance['status'] ?? null) ? $instance['status'] : null;

        return new CloudServerData(
            identifier: (string) $identifier, name: (string) ($instance['name'] ?? ''), region: (string) ($instance['region'] ?? ''),
            size: (string) ($instance['flavor']['name'] ?? ''), image: (string) ($instance['image']['name'] ?? ''),
            publicIp: $public, privateIp: $private, providerStatus: $status,
            readiness: $status === null ? CloudServerData::READINESS_UNKNOWN
                : ($status === 'ACTIVE' && $public !== null ? CloudServerData::READINESS_READY : CloudServerData::READINESS_NOT_READY),
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
        $response = $this->call('DELETE', "/cloud/project/{$this->project}/instance/".$this->id($identifier));

        return $response->successful() || $response->status() === 404;
    }

    /**
     * List the project's regions, DigitalOcean-shaped.
     *
     * @return list<array<string, mixed>>
     */
    public function regions(): array
    {
        $regions = [];
        foreach ((array) $this->ok($this->call('GET', "/cloud/project/{$this->project}/region"), 'region listing')->json() as $region) {
            if (is_string($region)) {
                $regions[] = ['slug' => $region, 'name' => $region, 'available' => true];
            }
        }

        return $regions;
    }

    /**
     * List the Linux flavors once each, DigitalOcean-shaped, priced from the public catalogue (hourly price × 730).
     *
     * @return list<array<string, mixed>>
     */
    public function sizes(): array
    {
        $prices = $this->hourlyPrices();
        $sizes = [];
        foreach ((array) $this->ok($this->call('GET', "/cloud/project/{$this->project}/flavor"), 'flavor listing')->json() as $flavor) {
            if (! is_array($flavor) || ! is_string($flavor['name'] ?? null) || ($flavor['osType'] ?? 'linux') !== 'linux' || isset($sizes[$flavor['name']])
                || str_starts_with($flavor['name'], 'win-') || ! ($flavor['available'] ?? true)) {
                continue;
            }
            $plan = is_string($flavor['planCodes']['hourly'] ?? null) ? $flavor['planCodes']['hourly'] : $flavor['name'].'.consumption';
            $sizes[$flavor['name']] = [
                'slug' => $flavor['name'], 'description' => $flavor['name'], 'memory' => (int) ($flavor['ram'] ?? 0), 'vcpus' => (int) ($flavor['vcpus'] ?? 0),
                'price_monthly' => isset($prices[$plan]) ? round($prices[$plan] * 730, 2) : null,
            ];
        }

        return array_values($sizes);
    }

    /**
     * List the Ubuntu releases offered, DigitalOcean-shaped. The image itself is found in the region at launch.
     *
     * @return list<array<string, mixed>>
     */
    public function images(): array
    {
        $images = [];
        foreach (self::IMAGES as $slug => $name) {
            $images[] = ['slug' => $slug, 'distribution' => 'Ubuntu', 'name' => substr($name, strlen('Ubuntu ')).' LTS'];
        }

        return $images;
    }

    /**
     * Make a signed read-only call that checks the keys work, for the provider's connection test.
     *
     * @return Response
     */
    public function ping(): Response
    {
        return $this->call('GET', "/cloud/project/{$this->project}");
    }

    /**
     * Find the ID of a flavor or image in a region.
     *
     * @param  string  $kind  flavor or image
     * @param  string  $region
     * @param  callable(array<string, mixed>): bool  $matches
     * @return string
     */
    private function regionalId(string $kind, string $region, callable $matches): string
    {
        $query = http_build_query(array_filter(['region' => $region, 'osType' => $kind === 'image' ? 'linux' : null]));
        foreach ((array) $this->ok($this->call('GET', "/cloud/project/{$this->project}/{$kind}?{$query}"), "{$kind} lookup")->json() as $entry) {
            if (is_array($entry) && $matches($entry) && is_string($entry['id'] ?? null)) {
                return $entry['id'];
            }
        }

        throw new RuntimeException("OVHcloud has no matching {$kind} in {$region}.");
    }

    /**
     * Read the hourly price of each Public Cloud plan from the public catalogue, in euros, cached for a day.
     *
     * @return array<string, float>
     */
    private function hourlyPrices(): array
    {
        /** @var array<string, float> */
        return Cache::remember('ovh.cloud-prices', now()->addDay(), function (): array {
            $response = Http::acceptJson()->connectTimeout(5)->timeout(20)->get($this->base.'/order/catalog/public/cloud', ['ovhSubsidiary' => 'IE']);
            $prices = [];
            foreach ((array) $response->json('addons', []) as $addon) {
                $price = is_array($addon) ? ($addon['pricings'][0]['price'] ?? null) : null;
                if (is_array($addon) && is_string($addon['planCode'] ?? null) && is_numeric($price)) {
                    $prices[$addon['planCode']] = (float) $price / 100_000_000;
                }
            }

            return $prices;
        });
    }

    /**
     * Make a signed API call: the signature covers the secret, consumer key, method, full URL, body and the API's
     * clock.
     *
     * @param  string  $method
     * @param  string  $path
     * @param  array<string, mixed>|null  $body
     * @return Response
     */
    private function call(string $method, string $path, ?array $body = null): Response
    {
        $url = $this->base.$path;
        $payload = $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) (time() + $this->clockOffset());
        $signature = '$1$'.sha1(implode('+', [$this->applicationSecret, $this->consumerKey, $method, $url, $payload, $timestamp]));
        $request = Http::withHeaders([
            'X-Ovh-Application' => $this->applicationKey, 'X-Ovh-Consumer' => $this->consumerKey,
            'X-Ovh-Timestamp' => $timestamp, 'X-Ovh-Signature' => $signature, 'User-Agent' => 'BuildPusher',
        ])->acceptJson()->connectTimeout(5)->timeout(20);
        if ($payload !== '') {
            $request = $request->withBody($payload, 'application/json');
        }

        return $request->send($method, $url);
    }

    /**
     * Get the difference between the API's clock and ours, cached for an hour.
     *
     * @return int
     */
    private function clockOffset(): int
    {
        return (int) Cache::remember('ovh.clock-offset.'.md5($this->base), now()->addHour(), function (): int {
            $time = Http::connectTimeout(5)->timeout(10)->get($this->base.'/auth/time')->body();

            return ctype_digit(trim($time)) ? (int) trim($time) - time() : 0;
        });
    }

    /**
     * Check an instance ID's shape before it goes into a URL.
     *
     * @param  int|string  $identifier
     * @return string
     */
    private function id(int|string $identifier): string
    {
        return preg_match('/\A[0-9a-f-]{36}\z/', (string) $identifier) === 1 ? (string) $identifier : throw new RuntimeException('That isn’t an OVHcloud instance ID.');
    }

    /**
     * Return the response when it succeeded, or throw without exposing it.
     *
     * @param  Response  $response
     * @param  string  $operation
     * @return Response
     */
    private function ok(Response $response, string $operation): Response
    {
        if (! $response->successful()) {
            throw new RuntimeException("OVHcloud {$operation} failed with HTTP {$response->status()}.");
        }

        return $response;
    }
}
