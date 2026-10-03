<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Data\Infrastructure\CloudServerData;
use App\Enums\ProviderType;
use App\Services\Infrastructure\Ec2;
use App\Services\Infrastructure\ServerPricing;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class Ec2ClientTest extends TestCase
{
    /**
     * Answer EC2 Query API calls by action, recording them.
     *
     * @param  array<string, string>  $answers  XML bodies by action
     * @return void
     */
    private function fakeEc2(array $answers): void
    {
        Http::fake(function (Request $request) use ($answers) {
            parse_str($request->body(), $form);
            $action = is_string($form['Action'] ?? null) ? $form['Action'] : '';

            return isset($answers[$action])
                ? Http::response('<?xml version="1.0" encoding="UTF-8"?><'.$action.'Response xmlns="http://ec2.amazonaws.com/doc/2016-11-15/">'.$answers[$action].'</'.$action.'Response>')
                : Http::response('<Response><Errors><Error><Code>InvalidInstanceID.NotFound</Code></Error></Errors></Response>', 400);
        });
    }

    /**
     * Check an instance launches with the region's newest Ubuntu AMI, a new security group open to SSH, HTTP and
     * HTTPS, the SSH key in its launch script and a 25 GB disk, and is looked up and terminated as region/id.
     *
     * @return void
     */
    public function test_ec2_launches_looks_up_and_terminates_instances(): void
    {
        $this->fakeEc2([
            'DescribeImages' => '<imagesSet><item><imageId>ami-old</imageId><creationDate>2026-01-01T00:00:00.000Z</creationDate></item><item><imageId>ami-new</imageId><creationDate>2026-09-01T00:00:00.000Z</creationDate></item></imagesSet>',
            'DescribeSecurityGroups' => '<securityGroupInfo/>',
            'CreateSecurityGroup' => '<return>true</return><groupId>sg-1</groupId>',
            'AuthorizeSecurityGroupIngress' => '<return>true</return>',
            'RunInstances' => '<instancesSet><item><instanceId>i-0abc</instanceId><instanceState><name>pending</name></instanceState></item></instancesSet>',
            'DescribeInstances' => '<reservationSet><item><instancesSet><item><instanceId>i-0abc</instanceId><instanceType>t3.small</instanceType><imageId>ami-new</imageId><instanceState><name>running</name></instanceState><ipAddress>3.8.1.2</ipAddress><privateIpAddress>172.31.0.5</privateIpAddress><tagSet><item><key>Name</key><value>web-1</value></item></tagSet></item></instancesSet></item></reservationSet>',
        ]);
        $client = new Ec2('AKIAEXAMPLEKEY12345:secretsecretsecretsecretsecret');
        $key = $client->createSshKey('web-1', 'ssh-ed25519 AAAAKEY buildpusher');

        $created = $client->createServer(['name' => 'web-1', 'region' => 'eu-west-2', 'size' => 't3.small', 'image' => 'ubuntu-24.04', 'ssh_keys' => [$key->fingerprint], 'user_data' => "#!/bin/bash\necho provision"]);

        $this->assertSame(['eu-west-2/i-0abc', CloudServerData::READINESS_NOT_READY], [$created->identifier, $created->readiness]);
        Http::assertSent(function (Request $request): bool {
            parse_str($request->body(), $form);
            if (($form['Action'] ?? null) !== 'RunInstances') {
                return false;
            }
            $launch = base64_decode(is_string($form['UserData'] ?? null) ? $form['UserData'] : '');

            return $request->url() === 'https://ec2.eu-west-2.amazonaws.com/' && $form['ImageId'] === 'ami-new' && $form['SecurityGroupId_1'] === 'sg-1'
                && $form['BlockDeviceMapping_1_Ebs_VolumeSize'] === '25' && str_contains($launch, "echo 'ssh-ed25519 AAAAKEY buildpusher' >> /root/.ssh/authorized_keys")
                && str_ends_with($launch, 'echo provision') && str_starts_with($request->header('Authorization')[0], 'AWS4-HMAC-SHA256 Credential=AKIAEXAMPLEKEY12345/');
        });
        Http::assertSent(function (Request $request): bool {
            parse_str($request->body(), $form);

            return ($form['Action'] ?? null) === 'AuthorizeSecurityGroupIngress' && $form['IpPermissions_3_FromPort'] === '443';
        });

        $found = $client->server('eu-west-2/i-0abc');
        $this->assertSame(['web-1', '3.8.1.2', '172.31.0.5', 't3.small', CloudServerData::READINESS_READY], [$found->name, $found->publicIp, $found->privateIp, $found->size, $found->readiness]);
        $this->assertTrue($client->deleteServer('eu-west-2/i-0abc'));
        $this->assertTrue($client->deleteServer('eu-west-2/i-gone'));
    }

    /**
     * Check the catalogue is DigitalOcean-shaped so pricing and right-sizing read it, and snapshots are images whose
     * disk snapshots are removed with them.
     *
     * @return void
     */
    public function test_ec2_prices_sizes_and_snapshots_instances(): void
    {
        $this->fakeEc2([
            'DescribeRegions' => '<regionInfo><item><regionName>eu-west-2</regionName></item><item><regionName>us-east-1</regionName></item></regionInfo>',
            'CreateImage' => '<imageId>ami-snap</imageId>',
            'DescribeImages' => '<imagesSet><item><imageId>ami-snap</imageId><blockDeviceMapping><item><ebs><snapshotId>snap-1</snapshotId></ebs></item></blockDeviceMapping></item></imagesSet>',
            'DeregisterImage' => '<return>true</return>',
            'DeleteSnapshot' => '<return>true</return>',
        ]);
        $client = new Ec2('AKIAEXAMPLEKEY12345:secretsecretsecretsecretsecret');

        $this->assertSame(['eu-west-2', 'us-east-1'], array_column($client->regions(), 'slug'));
        $this->assertSame(17.18, app(ServerPricing::class)->price(ProviderType::Ec2, $client->sizes(), 't3.small', 'eu-west-2'));
        $this->assertSame(['ubuntu-24.04', 'ubuntu-22.04'], array_column($client->images(), 'slug'));

        $this->assertSame('eu-west-2/ami-snap', $client->snapshotServer('eu-west-2/i-0abc', 'before updates'));
        $this->assertTrue($client->deleteSnapshot('eu-west-2/ami-snap'));
        Http::assertSent(function (Request $request): bool {
            parse_str($request->body(), $form);

            return ($form['Action'] ?? null) === 'DeleteSnapshot' && $form['SnapshotId'] === 'snap-1';
        });
    }
}
