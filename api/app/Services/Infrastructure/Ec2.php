<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Contracts\Infrastructure\ServerProvider;
use App\Contracts\Infrastructure\SnapshotsServers;
use App\Data\Infrastructure\CloudServerData;
use App\Data\Infrastructure\CloudSshKeyData;
use App\Support\AwsSignature;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;

/**
 * AWS EC2 instances through the EC2 Query API, with an IAM access key signed as AWS Signature Version 4. Instances
 * go in the region's default VPC with a "buildpusher" security group (SSH, HTTP and HTTPS) and a 25 GB gp3 disk.
 * EC2 key pairs are per region, so the SSH key is added by the launch script, and Ubuntu's AMI is looked up in the
 * region at launch from Canonical's images. An instance's ID is "region/instance-id". Prices are on-demand Linux
 * prices in us-east-1; other regions cost a little more, and the Costs page shows what AWS actually charged.
 */
class Ec2 implements ServerProvider, SnapshotsServers
{
    /** The EC2 API version the Query API calls use. */
    private const string VERSION = '2016-11-15';

    /** Canonical's AWS account, which publishes the official Ubuntu AMIs. */
    private const string CANONICAL = '099720109477';

    /** The region catalogue calls go to. */
    private const string CATALOG_REGION = 'us-east-1';

    /** The security group instances are launched in. */
    private const string SECURITY_GROUP = 'buildpusher';

    /**
     * The instance types offered, with vCPUs, memory (MB) and the on-demand Linux price a month in us-east-1
     * (730 hours) plus the 25 GB gp3 disk. x86 only, since the server scripts install amd64 packages.
     *
     * @var array<string, array{vcpus: int, memory: int, price: float}>
     */
    private const array TYPES = [
        't3.micro' => ['vcpus' => 2, 'memory' => 1024, 'price' => 9.59],
        't3.small' => ['vcpus' => 2, 'memory' => 2048, 'price' => 17.18],
        't3.medium' => ['vcpus' => 2, 'memory' => 4096, 'price' => 32.37],
        't3.large' => ['vcpus' => 2, 'memory' => 8192, 'price' => 62.74],
        't3.xlarge' => ['vcpus' => 4, 'memory' => 16384, 'price' => 123.47],
        'c6i.large' => ['vcpus' => 2, 'memory' => 4096, 'price' => 64.05],
        'c6i.xlarge' => ['vcpus' => 4, 'memory' => 8192, 'price' => 126.10],
        'm6i.large' => ['vcpus' => 2, 'memory' => 8192, 'price' => 72.08],
        'm6i.xlarge' => ['vcpus' => 4, 'memory' => 16384, 'price' => 142.16],
        'm6i.2xlarge' => ['vcpus' => 8, 'memory' => 32768, 'price' => 282.32],
        'r6i.large' => ['vcpus' => 2, 'memory' => 16384, 'price' => 93.98],
        'r6i.xlarge' => ['vcpus' => 4, 'memory' => 32768, 'price' => 185.96],
    ];

    /**
     * The Ubuntu releases offered, by the image name pattern Canonical publishes them under.
     *
     * @var array<string, array{name: string, pattern: string}>
     */
    private const array IMAGES = [
        'ubuntu-24.04' => ['name' => '24.04 LTS', 'pattern' => 'ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-amd64-server-*'],
        'ubuntu-22.04' => ['name' => '22.04 LTS', 'pattern' => 'ubuntu/images/hvm-ssd/ubuntu-jammy-22.04-amd64-server-*'],
    ];

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
     * Create a new Ec2 instance.
     *
     * @param  string  $token  "ACCESS_KEY_ID:SECRET_ACCESS_KEY" for an IAM user allowed EC2 actions
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
        return 'AWS EC2';
    }

    /**
     * Keep the public key to add at launch: EC2 key pairs are per region, so it isn't registered with AWS. The
     * "fingerprint" carries the key itself.
     *
     * @param  string  $name
     * @param  string  $publicKey
     * @return CloudSshKeyData
     */
    public function createSshKey(string $name, string $publicKey): CloudSshKeyData
    {
        return new CloudSshKeyData('ec2:'.base64_encode(trim($publicKey)), false);
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
     * Launch an instance of Ubuntu in a region's default VPC, with the SSH keys added before the provisioning script
     * runs.
     *
     * @param  array{name: string, region: string, size: string, image: int|string, ssh_keys?: list<int|string>, user_data?: string|null}  $parameters
     * @return CloudServerData
     */
    public function createServer(array $parameters): CloudServerData
    {
        $region = $parameters['region'];
        $image = $this->ami($region, (string) $parameters['image']);
        $group = $this->securityGroup($region);
        $keys = '';
        foreach ($parameters['ssh_keys'] ?? [] as $key) {
            $material = base64_decode(substr((string) $key, strlen('ec2:')), true);
            if (is_string($material) && preg_match('/\A[a-z0-9-]+ [A-Za-z0-9+\/=]+( [^\n]*)?\z/', $material) === 1) {
                $keys .= 'echo '.escapeshellarg($material)." >> /root/.ssh/authorized_keys\n";
            }
        }
        $script = (string) ($parameters['user_data'] ?? '');
        $launch = "#!/bin/bash\nmkdir -p /root/.ssh && chmod 700 /root/.ssh\n{$keys}chmod 600 /root/.ssh/authorized_keys\n".(str_starts_with($script, '#!') ? substr($script, (int) strpos($script, "\n") + 1) : $script);
        $name = substr((string) preg_replace('/[^A-Za-z0-9_.-]+/', '-', $parameters['name']), 0, 255);

        $response = $this->call($region, 'RunInstances', [
            'ImageId' => $image, 'InstanceType' => $parameters['size'], 'MinCount' => '1', 'MaxCount' => '1',
            'UserData' => base64_encode($launch), 'SecurityGroupId.1' => $group,
            'BlockDeviceMapping.1.DeviceName' => '/dev/sda1', 'BlockDeviceMapping.1.Ebs.VolumeSize' => '25',
            'BlockDeviceMapping.1.Ebs.VolumeType' => 'gp3', 'BlockDeviceMapping.1.Ebs.DeleteOnTermination' => 'true',
            'TagSpecification.1.ResourceType' => 'instance', 'TagSpecification.1.Tag.1.Key' => 'Name', 'TagSpecification.1.Tag.1.Value' => $name,
            'MetadataOptions.HttpTokens' => 'required',
        ]);
        $instance = $this->xml($response, 'instance launch')->instancesSet->item ?? null;
        $id = (string) ($instance->instanceId ?? '');
        if ($id === '') {
            throw new RuntimeException('EC2 instance launch returned no instance.');
        }

        return new CloudServerData(
            identifier: "{$region}/{$id}", name: $name, region: $region, size: $parameters['size'], image: (string) $parameters['image'],
            publicIp: null, privateIp: null, providerStatus: 'pending', readiness: CloudServerData::READINESS_NOT_READY,
        );
    }

    /**
     * Look up an instance.
     *
     * @param  int|string  $identifier  "region/instance-id"
     * @return CloudServerData
     */
    public function server(int|string $identifier): CloudServerData
    {
        [$region, $id] = $this->split((string) $identifier);
        $instance = $this->xml($this->call($region, 'DescribeInstances', ['InstanceId.1' => $id]), 'instance lookup')->reservationSet->item->instancesSet->item ?? null;
        if ($instance === null) {
            throw new RuntimeException('EC2 has no instance '.$id.'.');
        }
        $status = (string) $instance->instanceState->name;
        $public = (string) $instance->ipAddress !== '' ? (string) $instance->ipAddress : null;
        $name = $id;
        foreach ($instance->tagSet->item ?? [] as $tag) {
            if ((string) $tag->key === 'Name') {
                $name = (string) $tag->value;
            }
        }

        return new CloudServerData(
            identifier: "{$region}/{$id}", name: $name, region: $region, size: (string) $instance->instanceType, image: (string) $instance->imageId,
            publicIp: $public, privateIp: (string) $instance->privateIpAddress !== '' ? (string) $instance->privateIpAddress : null,
            providerStatus: $status !== '' ? $status : null,
            readiness: $status === '' ? CloudServerData::READINESS_UNKNOWN
                : ($status === 'running' && $public !== null ? CloudServerData::READINESS_READY : CloudServerData::READINESS_NOT_READY),
        );
    }

    /**
     * Terminate an instance; a missing one counts as deleted.
     *
     * @param  int|string  $identifier
     * @return bool
     */
    public function deleteServer(int|string $identifier): bool
    {
        [$region, $id] = $this->split((string) $identifier);
        $response = $this->call($region, 'TerminateInstances', ['InstanceId.1' => $id]);

        return $response->successful() || str_contains($response->body(), 'InvalidInstanceID.NotFound');
    }

    /**
     * List the regions the account has enabled, DigitalOcean-shaped.
     *
     * @return list<array<string, mixed>>
     */
    public function regions(): array
    {
        $regions = [];
        foreach ($this->xml($this->call(self::CATALOG_REGION, 'DescribeRegions', []), 'region listing')->regionInfo->item ?? [] as $region) {
            $name = (string) $region->regionName;
            if ($name !== '') {
                $regions[] = ['slug' => $name, 'name' => $name, 'available' => true];
            }
        }

        return $regions;
    }

    /**
     * List the instance types offered, DigitalOcean-shaped.
     *
     * @return list<array<string, mixed>>
     */
    public function sizes(): array
    {
        $sizes = [];
        foreach (self::TYPES as $type => $spec) {
            $sizes[] = ['slug' => $type, 'description' => $type, 'memory' => $spec['memory'], 'vcpus' => $spec['vcpus'], 'price_monthly' => $spec['price']];
        }

        return $sizes;
    }

    /**
     * List the Ubuntu releases offered, DigitalOcean-shaped. The AMI itself is found in the region at launch.
     *
     * @return list<array<string, mixed>>
     */
    public function images(): array
    {
        $images = [];
        foreach (self::IMAGES as $slug => $image) {
            $images[] = ['slug' => $slug, 'distribution' => 'Ubuntu', 'name' => $image['name']];
        }

        return $images;
    }

    /**
     * Make a signed read-only call that checks the key works, for the provider's connection test.
     *
     * @return Response
     */
    public function ping(): Response
    {
        return $this->call(self::CATALOG_REGION, 'DescribeRegions', []);
    }

    /**
     * Create an image of the instance without rebooting it; returns it as "region/ami-id".
     *
     * @param  int|string  $identifier
     * @param  string  $name
     * @return string
     */
    public function snapshotServer(int|string $identifier, string $name): string
    {
        [$region, $id] = $this->split((string) $identifier);
        $label = substr((string) preg_replace('/[^A-Za-z0-9()\[\] .\/\'@_-]+/', '-', $name), 0, 120);
        $image = (string) ($this->xml($this->call($region, 'CreateImage', ['InstanceId' => $id, 'Name' => $label, 'NoReboot' => 'true']), 'snapshot')->imageId ?? '');
        if ($image === '') {
            throw new RuntimeException('EC2 snapshot returned no image.');
        }

        return $region.'/'.$image;
    }

    /**
     * Delete an instance image and the disk snapshots behind it.
     *
     * @param  string  $snapshot  "region/ami-id"
     * @return bool
     */
    public function deleteSnapshot(string $snapshot): bool
    {
        [$region, $image] = $this->split($snapshot);
        $described = $this->call($region, 'DescribeImages', ['ImageId.1' => $image]);
        $snapshots = [];
        if ($described->successful()) {
            foreach ($this->xml($described, 'image lookup')->imagesSet->item->blockDeviceMapping->item ?? [] as $mapping) {
                if ((string) ($mapping->ebs->snapshotId ?? '') !== '') {
                    $snapshots[] = (string) $mapping->ebs->snapshotId;
                }
            }
        }
        $deregistered = $this->call($region, 'DeregisterImage', ['ImageId' => $image]);
        if (! $deregistered->successful() && ! str_contains($deregistered->body(), 'InvalidAMIID')) {
            return false;
        }
        foreach ($snapshots as $id) {
            $this->call($region, 'DeleteSnapshot', ['SnapshotId' => $id]);
        }

        return true;
    }

    /**
     * Find the newest official Ubuntu AMI for a release in a region.
     *
     * @param  string  $region
     * @param  string  $release  e.g. ubuntu-24.04
     * @return string
     */
    private function ami(string $region, string $release): string
    {
        $pattern = self::IMAGES[$release]['pattern'] ?? throw new RuntimeException('Choose Ubuntu 24.04 or 22.04.');
        $images = $this->xml($this->call($region, 'DescribeImages', [
            'Owner.1' => self::CANONICAL, 'Filter.1.Name' => 'name', 'Filter.1.Value.1' => $pattern,
            'Filter.2.Name' => 'state', 'Filter.2.Value.1' => 'available',
        ]), 'image lookup');
        $newest = null;
        $newestDate = '';
        foreach ($images->imagesSet->item ?? [] as $image) {
            if ((string) $image->creationDate > $newestDate) {
                $newestDate = (string) $image->creationDate;
                $newest = (string) $image->imageId;
            }
        }

        return $newest ?? throw new RuntimeException("EC2 has no Ubuntu image in {$region}.");
    }

    /**
     * Find or create the region's "buildpusher" security group in the default VPC, allowing SSH, HTTP and HTTPS in.
     *
     * @param  string  $region
     * @return string The group's ID.
     */
    private function securityGroup(string $region): string
    {
        $found = $this->xml($this->call($region, 'DescribeSecurityGroups', ['Filter.1.Name' => 'group-name', 'Filter.1.Value.1' => self::SECURITY_GROUP]), 'security group lookup');
        $id = (string) ($found->securityGroupInfo->item->groupId ?? '');
        if ($id !== '') {
            return $id;
        }
        $created = $this->xml($this->call($region, 'CreateSecurityGroup', ['GroupName' => self::SECURITY_GROUP, 'GroupDescription' => 'BuildPusher servers: SSH, HTTP and HTTPS']), 'security group creation');
        $id = (string) $created->groupId;
        $rules = ['GroupId' => $id];
        foreach ([22, 80, 443] as $index => $port) {
            $n = $index + 1;
            $rules += ["IpPermissions.{$n}.IpProtocol" => 'tcp', "IpPermissions.{$n}.FromPort" => (string) $port, "IpPermissions.{$n}.ToPort" => (string) $port,
                "IpPermissions.{$n}.IpRanges.1.CidrIp" => '0.0.0.0/0', "IpPermissions.{$n}.Ipv6Ranges.1.CidrIpv6" => '::/0'];
        }
        $this->xml($this->call($region, 'AuthorizeSecurityGroupIngress', $rules), 'security group rules');

        return $id;
    }

    /**
     * Call an EC2 Query API action in a region.
     *
     * @param  string  $region
     * @param  string  $action  e.g. DescribeInstances
     * @param  array<string, string>  $parameters
     * @return Response
     */
    private function call(string $region, string $action, array $parameters): Response
    {
        if (preg_match('/\A[a-z]{2}(-[a-z]+)+-\d\z/', $region) !== 1) {
            throw new RuntimeException('That isn’t an AWS region.');
        }
        $url = "https://ec2.{$region}.amazonaws.com/";
        $body = http_build_query(['Action' => $action, 'Version' => self::VERSION, ...$parameters], '', '&', PHP_QUERY_RFC3986);
        $headers = AwsSignature::headers($this->accessKey, $this->secret, $region, 'ec2', 'POST', $url, ['Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8'], $body);
        unset($headers['Host']);

        return Http::withHeaders([...$headers, 'User-Agent' => 'BuildPusher'])->withBody($body, 'application/x-www-form-urlencoded; charset=utf-8')
            ->connectTimeout(5)->timeout(20)->post($url);
    }

    /**
     * Parse a successful response's XML, or throw without exposing the response.
     *
     * @param  Response  $response
     * @param  string  $operation
     * @return SimpleXMLElement
     */
    private function xml(Response $response, string $operation): SimpleXMLElement
    {
        if (! $response->successful()) {
            $code = preg_match('/<Code>([A-Za-z0-9.]+)<\/Code>/', $response->body(), $match) === 1 ? " ({$match[1]})" : '';

            throw new RuntimeException("EC2 {$operation} failed with HTTP {$response->status()}{$code}.");
        }
        $xml = simplexml_load_string($response->body(), SimpleXMLElement::class, LIBXML_NONET);

        return $xml !== false ? $xml : throw new RuntimeException("EC2 {$operation} returned an unreadable answer.");
    }

    /**
     * Split an ID into its region and the AWS ID.
     *
     * @param  string  $identifier
     * @return array{0: string, 1: string}
     */
    private function split(string $identifier): array
    {
        [$region, $id] = array_pad(explode('/', $identifier, 2), 2, '');

        return [$region, $id];
    }
}
