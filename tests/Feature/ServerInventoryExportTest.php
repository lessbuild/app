<?php

namespace Tests\Feature;

use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerInventoryExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_filtered_inventory_export_is_owner_scoped_spreadsheet_safe_and_credential_free(): void
    {
        $owner = User::factory()->create();
        $provider = $this->provider($owner, '=DigitalOcean');
        $matching = $this->server($owner, $provider, '+Production Edge', Server::STATUS_FAILED, [
            'identifier' => 4242,
            'region' => '-nyc3',
            'size' => '@s-1vcpu-1gb',
            'image' => '=ubuntu-22-04-x64',
            'public_ip' => '203.0.113.10',
            'private_ip' => '10.0.0.10',
            'password' => 'root-password-never-export',
            'mysql_root_password' => 'mysql-password-never-export',
            'ssh_private_key' => 'ssh-private-key-never-export',
            'provisioning_error' => 'provisioning-error-never-export',
            'provisioned_at' => '2026-08-30 12:00:00',
        ]);
        $this->website($owner, $matching, 'First Website');
        $this->website($owner, $matching, 'Second Website');

        $healthy = $this->server($owner, $provider, 'Healthy Edge', Server::STATUS_ACTIVE, [
            'public_ip' => '203.0.113.10',
        ]);
        $this->website($owner, $healthy, 'Healthy Website');

        $other = User::factory()->create();
        $otherProvider = $this->provider($other, 'Private DigitalOcean');
        $foreign = $this->server($other, $otherProvider, 'Private Production Edge', Server::STATUS_FAILED, [
            'public_ip' => '203.0.113.10',
        ]);
        $this->website($other, $foreign, 'Private Website');

        $filters = [
            'search' => '203.0.113.10',
            'status' => Server::STATUS_FAILED,
        ];
        $response = $this->actingAs($owner)->get(route('servers.export', $filters));

        $response
            ->assertSuccessful()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString(
            'attachment; filename=lessbuild-servers-',
            (string) $response->headers->get('content-disposition'),
        );

        $content = $response->streamedContent();
        $this->assertStringNotContainsString('provider-token-never-export', $content);
        $this->assertStringNotContainsString('root-password-never-export', $content);
        $this->assertStringNotContainsString('mysql-password-never-export', $content);
        $this->assertStringNotContainsString('ssh-private-key-never-export', $content);
        $this->assertStringNotContainsString('provisioning-error-never-export', $content);

        $rows = $this->csvRows($content);
        $this->assertSame([
            'Server ID',
            'Name',
            'Cloud identifier',
            'Type',
            'Region',
            'Size',
            'Image',
            'Public IP',
            'Private IP',
            'Provider',
            'Provider type',
            'Status',
            'Website count',
            'Provisioned at',
            'Created at',
        ], $rows[0]);
        $this->assertCount(2, $rows);
        $this->assertSame((string) $matching->id, $rows[1][0]);
        $this->assertSame("'+Production Edge", $rows[1][1]);
        $this->assertSame('4242', $rows[1][2]);
        $this->assertSame(ServerTypeEnum::app->value, $rows[1][3]);
        $this->assertSame("'-nyc3", $rows[1][4]);
        $this->assertSame("'@s-1vcpu-1gb", $rows[1][5]);
        $this->assertSame("'=ubuntu-22-04-x64", $rows[1][6]);
        $this->assertSame('203.0.113.10', $rows[1][7]);
        $this->assertSame("'=DigitalOcean", $rows[1][9]);
        $this->assertSame(Provider::TYPE_DIGITALOCEAN, $rows[1][10]);
        $this->assertSame(Server::STATUS_FAILED, $rows[1][11]);
        $this->assertSame('2', $rows[1][12]);
        $this->assertSame($matching->provisioned_at->toIso8601String(), $rows[1][13]);

        $this->actingAs($owner)->get(route('servers.index', $filters))
            ->assertSuccessful()
            ->assertSee(route('servers.export', $filters));
    }

    public function test_empty_filtered_export_contains_only_the_header(): void
    {
        $owner = User::factory()->create();
        $provider = $this->provider($owner, 'DigitalOcean');
        $this->server($owner, $provider, 'Production', Server::STATUS_ACTIVE);

        $response = $this->actingAs($owner)->get(route('servers.export', [
            'status' => Server::STATUS_FAILED,
        ]));

        $response->assertSuccessful();
        $this->assertCount(1, $this->csvRows($response->streamedContent()));
    }

    public function test_export_requires_authentication(): void
    {
        $this->get(route('servers.export'))->assertRedirect(route('login'));
    }

    private function provider(User $user, string $name): Provider
    {
        return $user->providers()->create([
            'name' => $name,
            'provider' => Provider::TYPE_DIGITALOCEAN,
            'token' => 'provider-token-never-export',
            'description' => 'Cloud provider',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function server(
        User $user,
        Provider $provider,
        string $name,
        string $status,
        array $attributes = [],
    ): Server {
        return $user->servers()->create([
            'provider_id' => $provider->id,
            'name' => $name,
            'type' => ServerTypeEnum::app,
            'region' => 'nyc3',
            'size' => 's-1vcpu-1gb',
            'image' => 'ubuntu-22-04-x64',
            'provisioning_status' => $status,
            ...$attributes,
        ]);
    }

    private function website(User $user, Server $server, string $name): Website
    {
        return $user->websites()->create([
            'server_id' => $server->id,
            'name' => $name,
            'url' => str($name)->slug().'.example.com',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
    }

    /** @return list<list<string|null>> */
    private function csvRows(string $content): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $stream = fopen('php://temp', 'w+b');
        $this->assertNotFalse($stream);
        fwrite($stream, substr($content, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }
}
