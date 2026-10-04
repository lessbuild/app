<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Enums\ServerType;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\Website;
use App\Services\Infrastructure\WebsiteCaddyConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class RuntimeVersionsTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check that a website switches PHP version (installed beside others, site repointed, Caddy validated) and a
     * server switches Node.js version, both from their settings.
     *
     * @return void
     */
    public function test_websites_switch_php_and_servers_switch_node(): void
    {
        $this->fakeInfrastructure();
        $project = Project::factory()->withServices(['infrastructure'])->create();
        $owner = $this->ownerOf($project);
        $server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $project->account_id])->id, 'type' => ServerType::App]);
        $website = Website::factory()->create(['server_id' => $server->id, 'deployment_slug' => 'shop']);
        $this->assertStringContainsString('php_fastcgi unix//var/run/php/php8.4-fpm.sock', app(WebsiteCaddyConfiguration::class)->php($website, '/var/www/shop/current/public'));

        $base = "/api/app/projects/{$project->id}/infrastructure/websites/{$website->id}";
        $this->actingAs($owner)->getJson($base)->assertOk()->assertJsonPath('website.phpVersion', fn (string $version): bool => $version !== '');
        $this->actingAs($owner)->putJson("{$base}/php-version", ['php_version' => '7.4'])->assertJsonValidationErrors('php_version');
        $this->actingAs($owner)->putJson("{$base}/php-version", ['php_version' => '8.3'])->assertJsonRedirect("{$base}?tab=settings");
        $this->assertSame('8.3', $website->refresh()->php_version);
        $command = end($this->shell->ran)['command'] ?? '';
        $this->assertStringContainsString('if [ ! -x /usr/sbin/php-fpm8.3 ]', $command);
        $this->assertStringContainsString('install -y php8.3 php8.3-fpm', $command);
        $this->assertStringContainsString("systemctl enable --now 'php8.3-fpm'", $command);
        $this->assertStringContainsString(base64_encode(app(WebsiteCaddyConfiguration::class)->php($website, $website->deploymentPath('current').'/public')), $command);
        $this->assertStringContainsString('caddy validate --config /etc/caddy/Caddyfile', $command);

        $serverBase = "/api/app/projects/{$project->id}/infrastructure/servers/{$server->id}";
        $this->actingAs($owner)->getJson($serverBase)->assertOk()->assertJsonPath('server.installsNode', true);
        $this->actingAs($owner)->putJson("{$serverBase}/node-version", ['node_version' => '16'])->assertJsonValidationErrors('node_version');
        $this->actingAs($owner)->putJson("{$serverBase}/node-version", ['node_version' => '22'])->assertJsonRedirect("{$serverBase}?tab=settings");
        $this->assertSame('22', $server->refresh()->node_version);
        $this->assertStringContainsString("n 22\nhash -r\nnode --version | grep -q '^v22\\.'", end($this->shell->ran)['command'] ?? '');
    }
}
