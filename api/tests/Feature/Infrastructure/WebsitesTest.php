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
        $this->base = "/api/app/projects/{$this->project->id}/infrastructure/websites";
        $this->withoutMiddleware(RequirePassword::class);
    }

    /**
     * A website is set up on its server and goes live through callbacks.
     */
    public function test_a_website_is_set_up_on_its_server_and_goes_live_through_callbacks(): void
    {
        $this->actingAs($this->owner)->postJson($this->base, $this->website())->assertSuccessful()->assertJsonStructure(['secrets' => ['database', 'database_name']]);

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
        $this->actingAs($this->owner)->getJson("{$this->base}/{$website->id}")->assertOk()->assertJsonPath('website.status', 'active')->assertJsonPath('website.url', 'shop.example.com')->assertSee('all done');
        $this->post("/websites/{$website->id}/provisioning/callback/status", ['status' => 1])->assertForbidden();
    }

    /**
     * Moving a website sets it up again and removes the old copy once live.
     */
    public function test_moving_a_website_sets_it_up_again_and_removes_the_old_copy_once_live(): void
    {
        $website = Website::factory()->create(['server_id' => $this->server->id, 'url' => 'old.example.com']);
        $target = Server::factory()->create(['provider_id' => $this->server->provider_id]);

        $this->actingAs($this->owner)->putJson("{$this->base}/{$website->id}", $this->website(['server_id' => $target->id, 'url' => 'new.example.com', 'name' => $website->name]))->assertSuccessful();
        $website->refresh();
        $this->assertSame([$target->id, $this->server->id, Website::STATUS_PROVISIONING], [$website->server_id, $website->previous_server_id, $website->provisioning_status]);
        $this->assertSame('new.example.com', $website->domains()->where('type', 'primary')->value('hostname'));

        $this->post(ProvisioningCallbackUrl::websiteStatus($website), ['status' => 3])->assertNoContent();
        $this->assertNull($this->reload($website)->previous_server_id);
        $removal = collect($this->shell->ran)->firstWhere('server', $this->server->id);
        $this->assertIsArray($removal);
        $this->assertStringContainsString("rm -rf -- '/var/www/{$website->deployment_slug}'", $removal['command']);
    }

    /**
     * Domains are added with cloudflare and applied to caddy.
     */
    public function test_domains_are_added_with_cloudflare_and_applied_to_caddy(): void
    {
        $website = Website::factory()->create(['server_id' => $this->server->id, 'url' => 'shop.example.com']);
        $cloudflare = Provider::factory()->type(ProviderType::Cloudflare)->create(['account_id' => $this->project->account_id]);
        Http::fake([
            '*/zones?*' => Http::response(['result' => [['id' => 'zone-a', 'name' => 'example.com'], ['id' => 'zone-b', 'name' => 'shop.example.com']]]),
            '*/zones/zone-b/dns_records' => Http::response(['result' => ['id' => 'rec-1']]),
            '*/zones/zone-b/dns_records/rec-1' => Http::response(['result' => ['id' => 'rec-1']]),
        ]);

        $this->actingAs($this->owner)->postJson("{$this->base}/{$website->id}/domains", ['hostname' => 'www.shop.example.com', 'type' => 'alias', 'dns_provider_id' => $cloudflare->id])->assertSuccessful();
        $domain = WebsiteDomain::query()->where('type', 'alias')->sole();
        $this->assertSame(['zone-b:rec-1', 'active'], [$domain->dns_record_id, $domain->dns_status]);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['content'] === '203.0.113.9' && $request['type'] === 'A' && $request['proxied'] === false);
        $caddy = (string) (collect($this->shell->ran)->last()['command'] ?? '');
        preg_match("/printf '%s' '([A-Za-z0-9+\\/=]+)'/", $caddy, $match);
        $this->assertStringStartsWith("shop.example.com, www.shop.example.com {\n", (string) base64_decode($match[1] ?? '', true));

        $this->actingAs($this->owner)->postJson("{$this->base}/{$website->id}/domains", ['hostname' => 'old-shop.example.com', 'type' => 'redirect'])->assertJsonValidationErrors('redirect_url');
        $this->actingAs($this->owner)->postJson("{$this->base}/{$website->id}/domains", ['hostname' => 'www.shop.example.com', 'type' => 'alias'])->assertJsonValidationErrors('hostname');
        $git = Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $this->project->account_id]);
        $this->actingAs($this->owner)->postJson("{$this->base}/{$website->id}/domains", ['hostname' => 'x.example.com', 'type' => 'alias', 'dns_provider_id' => $git->id])->assertJsonValidationErrors('dns_provider_id');
        $primary = $website->domains()->where('type', 'primary')->value('id');
        $this->actingAs($this->owner)->deleteJson("{$this->base}/{$website->id}/domains/{$primary}")->assertJsonValidationErrors('domain');
        Http::fake(['*' => Http::response([], 200)]);
        $this->actingAs($this->owner)->deleteJson("{$this->base}/{$website->id}/domains/{$domain->id}")->assertSuccessful();
        $this->assertModelMissing($domain);
    }

    /**
     * Check domains' records are managed at DigitalOcean DNS, Hetzner DNS and Route 53 too: created in the most
     * specific zone with a relative name, updated in place, removed with the domain, and CDN settings stay
     * Cloudflare-only.
     *
     * @return void
     */
    public function test_domains_are_managed_at_digitalocean_hetzner_and_route53(): void
    {
        $website = Website::factory()->create(['server_id' => $this->server->id, 'url' => 'shop.example.com']);
        $digitalOcean = Provider::factory()->type(ProviderType::DigitalOcean)->create(['account_id' => $this->project->account_id, 'token' => 'do-token']);
        $hetzner = Provider::factory()->type(ProviderType::HetznerDns)->create(['account_id' => $this->project->account_id, 'token' => 'hz-token']);
        $route53 = Provider::factory()->type(ProviderType::Route53)->create(['account_id' => $this->project->account_id, 'token' => 'AKIAEXAMPLE000001:'.str_repeat('s', 40)]);
        Http::fake([
            'api.digitalocean.com/v2/domains?*' => Http::response(['domains' => [['name' => 'example.com'], ['name' => 'other.com']]]),
            'api.digitalocean.com/v2/domains/example.com/records' => Http::response(['domain_record' => ['id' => 111]], 201),
            'api.digitalocean.com/v2/domains/example.com/records/111' => Http::response([], 204),
            'dns.hetzner.com/api/v1/zones*' => Http::response(['zones' => [['id' => 'hz-zone', 'name' => 'example.com']]]),
            'dns.hetzner.com/api/v1/records' => Http::response(['record' => ['id' => 'hz-rec']]),
            'route53.amazonaws.com/2013-04-01/hostedzone?*' => Http::response('<ListHostedZonesResponse><HostedZones><HostedZone><Id>/hostedzone/Z123ABC</Id><Name>example.com.</Name></HostedZone></HostedZones></ListHostedZonesResponse>'),
            'route53.amazonaws.com/2013-04-01/hostedzone/Z123ABC/rrset' => Http::response('<ChangeResourceRecordSetsResponse/>'),
        ]);
        $add = fn (string $hostname, Provider $provider) => $this->actingAs($this->owner)->postJson("{$this->base}/{$website->id}/domains", ['hostname' => $hostname, 'type' => 'alias', 'dns_provider_id' => $provider->id]);

        $add('www.shop.example.com', $digitalOcean)->assertSuccessful();
        $do = WebsiteDomain::query()->where('hostname', 'www.shop.example.com')->sole();
        $this->assertSame(['example.com:111', 'active'], [$do->dns_record_id, $do->dns_status]);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.digitalocean.com/v2/domains/example.com/records' && $request['name'] === 'www.shop' && $request['data'] === '203.0.113.9');

        $add('api.example.com', $hetzner)->assertSuccessful();
        $this->assertSame('hz-zone:hz-rec', WebsiteDomain::query()->where('hostname', 'api.example.com')->value('dns_record_id'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://dns.hetzner.com/api/v1/records' && $request->hasHeader('Auth-API-Token', 'hz-token') && $request['name'] === 'api' && $request['zone_id'] === 'hz-zone');

        $add('example.com', $route53)->assertSuccessful();
        $this->assertSame('Z123ABC:A', WebsiteDomain::query()->where('hostname', 'example.com')->value('dns_record_id'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/hostedzone/Z123ABC/rrset') && str_contains($request->body(), '<Action>UPSERT</Action>') && str_contains($request->body(), '<Name>example.com.</Name>') && str_starts_with((string) ($request->header('Authorization')[0] ?? ''), 'AWS4-HMAC-SHA256'));

        $this->actingAs($this->owner)->putJson("{$this->base}/{$website->id}/domains/{$do->id}/edge", ['cdn_proxied' => '1'])->assertJsonValidationErrors('edge');
        $this->actingAs($this->owner)->deleteJson("{$this->base}/{$website->id}/domains/{$do->id}")->assertSuccessful();
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'https://api.digitalocean.com/v2/domains/example.com/records/111');
    }

    /**
     * Importing adopts an existing directory and deleting removes it from the server.
     */
    public function test_importing_adopts_an_existing_directory_and_deleting_removes_it_from_the_server(): void
    {
        $this->shell->reply('', 1);
        $this->actingAs($this->owner)->postJson("{$this->base}/import", ['server_id' => $this->server->id, 'name' => 'Legacy', 'url' => 'legacy.example.com', 'deployment_slug' => 'legacy'])->assertJsonValidationErrors('deployment_slug');
        $this->actingAs($this->owner)->postJson("{$this->base}/import", ['server_id' => $this->server->id, 'name' => 'Legacy', 'url' => 'https://Legacy.example.com/', 'deployment_slug' => 'legacy'])->assertSuccessful();

        $website = Website::query()->sole();
        $this->assertSame(['legacy.example.com', Website::STATUS_ACTIVE], [$website->url, $website->provisioning_status]);
        $this->assertSame([], $this->scripts->started);

        $this->actingAs($this->owner)->deleteJson("{$this->base}/{$website->id}")->assertJsonRedirect($this->base);
        $this->assertSoftDeleted($website);
        $this->assertStringContainsString('DROP DATABASE IF EXISTS `legacy`', (string) (collect($this->shell->ran)->last()['command'] ?? ''));
    }

    /**
     * Websites need an app server in the account and respect the plan.
     */
    public function test_websites_need_an_app_server_in_the_account_and_respect_the_plan(): void
    {
        $cache = Server::factory()->create(['provider_id' => $this->server->provider_id, 'type' => 'cache']);
        $foreign = Server::factory()->create();
        $this->actingAs($this->owner)->postJson($this->base, $this->website(['server_id' => $cache->id]))->assertJsonValidationErrors('server_id');
        $this->actingAs($this->owner)->postJson($this->base, $this->website(['server_id' => $foreign->id]))->assertJsonValidationErrors('server_id');
        $this->actingAs($this->owner)->postJson($this->base, $this->website(['url' => 'bad host/path']))->assertJsonValidationErrors('url');

        $this->onTier($this->project, 'deploy', 'free');
        Website::factory()->create(['server_id' => $this->server->id]);
        $this->actingAs($this->owner)->postJson($this->base, $this->website())->assertJsonValidationErrors('plan');

        $viewer = User::factory()->create();
        $this->addMember($this->project, $viewer, AccountRole::Viewer);
        $this->actingAs($viewer)->getJson($this->base)->assertOk()->assertJsonPath('canManage', false)->assertJsonPath('options', null);
        $this->actingAs($viewer)->postJson($this->base, $this->website())->assertForbidden();
    }

    /**
     * The website page renders every tab and shows the requested one.
     */
    public function test_the_website_page_renders_every_tab_and_shows_the_requested_one(): void
    {
        $website = Website::factory()->create(['server_id' => $this->server->id]);
        // One answer carries every tab's data, so switching tabs needs no request.
        $this->actingAs($this->owner)->getJson("{$this->base}/{$website->id}")->assertOk()
            ->assertJsonStructure(['website', 'health', 'domains', 'inspection', 'databaseUsers', 'backups', 'schedules', 'options', 'caddyConfiguration'])
            ->assertJsonPath('canBrowseFiles', true);
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
