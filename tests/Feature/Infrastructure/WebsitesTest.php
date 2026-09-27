<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Enums\AccountRole;
use App\Enums\ProviderType;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteDomain;
use App\Services\Infrastructure\ProvisioningCallbackUrl;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class WebsitesTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private Server $server;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $this->project = Project::factory()->withServices(['infrastructure'])->create();
        $this->owner = $this->ownerOf($this->project);
        $this->onTier($this->project, 'deploy', 'pro');
        $this->server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $this->project->account_id])->id, 'public_ip' => '203.0.113.9']);
        $this->base = "/projects/{$this->project->id}/infrastructure/websites";
        $this->withoutMiddleware(RequirePassword::class);
    }

    public function test_a_website_is_set_up_on_its_server_and_goes_live_through_callbacks(): void
    {
        $this->actingAs($this->owner)->post($this->base, $this->website())->assertRedirect()->assertSessionHas('secrets');

        $website = Website::query()->sole();
        $this->assertSame('shop', $website->deployment_slug);
        $this->assertSame("APP_ENV=production\n", $website->env_file);
        $this->assertSame(Website::STATUS_PROVISIONING, $website->provisioning_status);
        $this->assertSame('primary', $website->domains()->sole()->type);
        $script = $this->scripts->started[0]['script'];
        $this->assertStringContainsString("'/etc/caddy/websites/shop.conf'", $script);
        $this->assertStringContainsString('CREATE DATABASE IF NOT EXISTS `shop`', $script);
        $this->assertStringContainsString("/websites/{$website->id}/provisioning/callback/status", $script);

        foreach ([1, 2, 3] as $stage) {
            $this->post(ProvisioningCallbackUrl::websiteStatus($website), ['status' => $stage])->assertNoContent();
        }
        $this->assertSame(Website::STATUS_ACTIVE, $this->reload($website)->provisioning_status);
        $this->post(ProvisioningCallbackUrl::websiteLog($website), ['log' => "all done\n"])->assertNoContent();
        $this->actingAs($this->owner)->get("{$this->base}/{$website->id}")->assertOk()->assertSee('Live')->assertSee('shop.example.com')->assertSee('all done');
        $this->post("/websites/{$website->id}/provisioning/callback/status", ['status' => 1])->assertForbidden();
    }

    public function test_moving_a_website_sets_it_up_again_and_removes_the_old_copy_once_live(): void
    {
        $website = Website::factory()->create(['server_id' => $this->server->id, 'url' => 'old.example.com']);
        $target = Server::factory()->create(['provider_id' => $this->server->provider_id]);

        $this->actingAs($this->owner)->put("{$this->base}/{$website->id}", $this->website(['server_id' => $target->id, 'url' => 'new.example.com', 'name' => $website->name]))->assertRedirect();
        $website->refresh();
        $this->assertSame([$target->id, $this->server->id, Website::STATUS_PROVISIONING], [$website->server_id, $website->previous_server_id, $website->provisioning_status]);
        $this->assertSame('new.example.com', $website->domains()->where('type', 'primary')->value('hostname'));

        $this->post(ProvisioningCallbackUrl::websiteStatus($website), ['status' => 3])->assertNoContent();
        $this->assertNull($this->reload($website)->previous_server_id);
        $removal = collect($this->shell->ran)->firstWhere('server', $this->server->id);
        $this->assertIsArray($removal);
        $this->assertStringContainsString("rm -rf -- '/var/www/{$website->deployment_slug}'", $removal['command']);
    }

    public function test_domains_are_added_with_cloudflare_and_applied_to_caddy(): void
    {
        $website = Website::factory()->create(['server_id' => $this->server->id, 'url' => 'shop.example.com']);
        $cloudflare = Provider::factory()->type(ProviderType::Cloudflare)->create(['account_id' => $this->project->account_id]);
        Http::fake([
            '*/zones?*' => Http::response(['result' => [['id' => 'zone-a', 'name' => 'example.com'], ['id' => 'zone-b', 'name' => 'shop.example.com']]]),
            '*/zones/zone-b/dns_records' => Http::response(['result' => ['id' => 'rec-1']]),
            '*/zones/zone-b/dns_records/rec-1' => Http::response(['result' => ['id' => 'rec-1']]),
        ]);

        $this->actingAs($this->owner)->post("{$this->base}/{$website->id}/domains", ['hostname' => 'www.shop.example.com', 'type' => 'alias', 'dns_provider_id' => $cloudflare->id])->assertSessionHas('status');
        $domain = WebsiteDomain::query()->where('type', 'alias')->sole();
        $this->assertSame(['zone-b:rec-1', 'active'], [$domain->dns_record_id, $domain->dns_status]);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['content'] === '203.0.113.9' && $request['type'] === 'A' && $request['proxied'] === false);
        $caddy = (string) (collect($this->shell->ran)->last()['command'] ?? '');
        preg_match("/printf '%s' '([A-Za-z0-9+\\/=]+)'/", $caddy, $match);
        $this->assertStringStartsWith("shop.example.com, www.shop.example.com {\n", (string) base64_decode($match[1] ?? '', true));

        $this->actingAs($this->owner)->post("{$this->base}/{$website->id}/domains", ['hostname' => 'old-shop.example.com', 'type' => 'redirect'])->assertSessionHasErrors('redirect_url');
        $this->actingAs($this->owner)->post("{$this->base}/{$website->id}/domains", ['hostname' => 'www.shop.example.com', 'type' => 'alias'])->assertSessionHasErrors('hostname');
        $this->actingAs($this->owner)->post("{$this->base}/{$website->id}/domains", ['hostname' => 'x.example.com', 'type' => 'alias', 'dns_provider_id' => $this->server->provider_id])->assertSessionHasErrors('dns_provider_id');
        $primary = $website->domains()->where('type', 'primary')->value('id');
        $this->actingAs($this->owner)->delete("{$this->base}/{$website->id}/domains/{$primary}")->assertSessionHasErrors('domain');
        Http::fake(['*' => Http::response([], 200)]);
        $this->actingAs($this->owner)->delete("{$this->base}/{$website->id}/domains/{$domain->id}")->assertRedirect();
        $this->assertModelMissing($domain);
    }

    public function test_importing_adopts_an_existing_directory_and_deleting_removes_it_from_the_server(): void
    {
        $this->shell->reply('', 1);
        $this->actingAs($this->owner)->post("{$this->base}/import", ['server_id' => $this->server->id, 'name' => 'Legacy', 'url' => 'legacy.example.com', 'deployment_slug' => 'legacy'])->assertSessionHasErrors('deployment_slug');
        $this->actingAs($this->owner)->post("{$this->base}/import", ['server_id' => $this->server->id, 'name' => 'Legacy', 'url' => 'https://Legacy.example.com/', 'deployment_slug' => 'legacy'])->assertRedirect();

        $website = Website::query()->sole();
        $this->assertSame(['legacy.example.com', Website::STATUS_ACTIVE], [$website->url, $website->provisioning_status]);
        $this->assertSame([], $this->scripts->started);

        $this->actingAs($this->owner)->delete("{$this->base}/{$website->id}")->assertRedirect($this->base);
        $this->assertSoftDeleted($website);
        $this->assertStringContainsString('DROP DATABASE IF EXISTS `legacy`', (string) (collect($this->shell->ran)->last()['command'] ?? ''));
    }

    public function test_websites_need_an_app_server_in_the_account_and_respect_the_plan(): void
    {
        $cache = Server::factory()->create(['provider_id' => $this->server->provider_id, 'type' => 'cache']);
        $foreign = Server::factory()->create();
        $this->actingAs($this->owner)->post($this->base, $this->website(['server_id' => $cache->id]))->assertSessionHasErrors('server_id');
        $this->actingAs($this->owner)->post($this->base, $this->website(['server_id' => $foreign->id]))->assertSessionHasErrors('server_id');
        $this->actingAs($this->owner)->post($this->base, $this->website(['url' => 'bad host/path']))->assertSessionHasErrors('url');

        $this->onTier($this->project, 'deploy', 'free');
        Website::factory()->create(['server_id' => $this->server->id]);
        $this->actingAs($this->owner)->post($this->base, $this->website())->assertSessionHasErrors('plan');

        $viewer = User::factory()->create();
        $this->addMember($this->project, $viewer, AccountRole::Viewer);
        $this->actingAs($viewer)->get($this->base)->assertOk()->assertDontSee('Create a website');
        $this->actingAs($viewer)->post($this->base, $this->website())->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function website(array $overrides = []): array
    {
        return ['name' => 'Shop', 'server_id' => $this->server->id, 'url' => 'shop.example.com', 'env_file' => "APP_ENV=production\n", ...$overrides];
    }
}
