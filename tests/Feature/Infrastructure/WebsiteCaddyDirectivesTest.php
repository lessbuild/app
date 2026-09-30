<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Jobs\Infrastructure\ApplyWebsiteDomains;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\Website;
use App\Services\Infrastructure\ServerShell;
use App\Services\Infrastructure\WebsiteCaddyConfiguration;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class WebsiteCaddyDirectivesTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check that a website's own Caddy directives go inside its site block, are checked before use, and that a
     * refused change keeps the old file and shows why.
     *
     * @return void
     */
    public function test_custom_directives_are_validated_applied_and_rolled_back_when_refused(): void
    {
        $this->fakeInfrastructure();
        $this->withoutMiddleware(RequirePassword::class);
        $project = Project::factory()->withServices(['infrastructure'])->create();
        $owner = $this->ownerOf($project);
        $server = Server::factory()->create(['account_id' => $project->account_id, 'provider_id' => Provider::factory()->create(['account_id' => $project->account_id])->id]);
        $website = Website::factory()->create(['server_id' => $server->id, 'deployment_slug' => 'shop-live', 'provisioning_status' => Website::STATUS_ACTIVE]);
        $base = "/projects/{$project->id}/infrastructure/websites/{$website->id}";

        $this->actingAs($owner)->get("{$base}?tab=settings")->assertOk()->assertSee(__('Web server (Caddy)'))->assertSee(__('See the full configuration'));
        $this->actingAs($owner)->put("{$base}/caddy", ['caddy_directives' => "handle /api {\n    respond 200"])->assertSessionHasErrors('caddy_directives');

        $this->actingAs($owner)->put("{$base}/caddy", ['caddy_directives' => "header X-Frame-Options DENY\r\nredir /old /new 301"])->assertRedirect("{$base}?tab=settings");
        $config = app(WebsiteCaddyConfiguration::class)->php($website->refresh(), '/srv/public');
        $this->assertStringContainsString("    # Your directives (Website settings)\n    header X-Frame-Options DENY\n    redir /old /new 301\n}", $config);
        $command = (string) (collect($this->shell->ran)->last()['command'] ?? '');
        $this->assertStringContainsString('cp \'/etc/caddy/websites/shop-live.conf\' \'/etc/caddy/websites/shop-live.conf\'.previous', $command);
        $this->assertStringContainsString('caddy validate --config /etc/caddy/Caddyfile', $command);
        $this->assertStringContainsString(base64_encode(app(WebsiteCaddyConfiguration::class)->php($website, $website->deploymentPath('current').'/public')), $command);

        Queue::fake([ApplyWebsiteDomains::class]);
        $this->actingAs($owner)->put("{$base}/caddy", ['caddy_directives' => 'nonsense_directive on'])->assertRedirect();
        $this->shell->reply('Error: unrecognized directive: nonsense_directive', 1);
        Queue::assertPushed(ApplyWebsiteDomains::class, function (ApplyWebsiteDomains $job): bool {
            $this->assertThrows(fn () => $job->handle(app(ServerShell::class), app(WebsiteCaddyConfiguration::class)), RuntimeException::class);

            return true;
        });
        $this->assertStringContainsString('unrecognized directive', (string) $website->refresh()->caddy_error);
        $this->actingAs($owner)->get("{$base}?tab=settings")->assertSee(__('Caddy refused the last change; the previous configuration is still in use.'))->assertSee('unrecognized directive');
    }
}
