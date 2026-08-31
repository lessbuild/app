<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\Server;
use App\Models\ServerLogSnapshot;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalLogDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_download_exact_website_provisioning_output(): void
    {
        [$owner, , $website] = $this->infrastructure();
        $output = "Creating database\r\n\x1b[32mWebsite ready\x1b[0m\n";
        $website->logs()->create([
            'type' => Website::PROVISIONING_LOG_TYPE,
            'log' => $output,
        ]);

        $this->actingAs($owner)->get(route('websites.show', $website))
            ->assertSuccessful()
            ->assertSee(route('websites.provisioning-log.download', $website), false);

        $response = $this->get(route('websites.provisioning-log.download', $website));

        $this->assertLogDownload(
            $response,
            $output,
            "lessbuild-website-{$website->id}-provisioning.log",
        );
    }

    public function test_owner_can_download_an_allowlisted_server_log_snapshot(): void
    {
        [$owner, $server] = $this->infrastructure();
        $output = "Service started\r\nNo errors\n";
        $server->logSnapshots()->create([
            'type' => 'caddy',
            'status' => ServerLogSnapshot::STATUS_READY,
            'log' => $output,
            'refreshed_at' => now(),
        ]);

        $this->actingAs($owner)->get(route('servers.show', ['server' => $server, 'log' => 'caddy']))
            ->assertSuccessful()
            ->assertSee(route('servers.logs.download', ['server' => $server, 'type' => 'caddy']), false);

        $response = $this->get(route('servers.logs.download', [
            'server' => $server,
            'type' => 'caddy',
        ]));

        $this->assertLogDownload(
            $response,
            $output,
            "lessbuild-server-{$server->id}-caddy.log",
        );
    }

    public function test_operational_log_downloads_enforce_ownership_and_existing_output(): void
    {
        [$owner, $server, $website] = $this->infrastructure();
        $intruder = User::factory()->create();
        $website->logs()->create([
            'type' => Website::PROVISIONING_LOG_TYPE,
            'log' => 'Private website output',
        ]);
        $server->logSnapshots()->create([
            'type' => 'apt',
            'status' => ServerLogSnapshot::STATUS_READY,
            'log' => 'Private server output',
        ]);

        $this->actingAs($intruder)
            ->get(route('websites.provisioning-log.download', $website))
            ->assertForbidden();
        $this->get(route('servers.logs.download', ['server' => $server, 'type' => 'apt']))
            ->assertForbidden();

        $this->actingAs($owner)
            ->get(route('servers.logs.download', ['server' => $server, 'type' => 'php']))
            ->assertNotFound();
        $this->get("/servers/{$server->id}/logs/../../etc/passwd")
            ->assertNotFound();
    }

    private function assertLogDownload($response, string $output, string $filename): void
    {
        $response
            ->assertSuccessful()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertHeader('Content-Disposition', "attachment; filename={$filename}")
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($output, $response->getContent());
    }

    /** @return array{User, Server, Website} */
    private function infrastructure(): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'DigitalOcean',
            'provider' => Provider::TYPE_DIGITALOCEAN,
            'token' => 'cloud-secret',
            'description' => 'Cloud provider',
        ]);
        $server = $owner->servers()->create([
            'provider_id' => $provider->id,
            'name' => 'Production',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $owner->websites()->create([
            'server_id' => $server->id,
            'name' => 'Application',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'url' => 'app.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);

        return [$owner, $server, $website];
    }
}
