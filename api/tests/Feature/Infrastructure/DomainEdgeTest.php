<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Enums\ProviderType;
use App\Events\Deploy\DeployFinished;
use App\Models\Build;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\Website;
use App\Models\WebsiteDomain;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class DomainEdgeTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check a Cloudflare-managed domain's edge: proxied through the CDN, countries and addresses blocked and a rate
     * limit set, all without touching the zone's other rules, and the cache purged after a successful deploy.
     *
     * @return void
     */
    public function test_cdn_firewall_and_rate_limits_are_set_at_cloudflare(): void
    {
        $this->fakeInfrastructure();
        $this->withoutMiddleware(RequirePassword::class);
        $api = 'https://api.cloudflare.com/client/v4';
        $custom = ['id' => 'mine', 'action' => 'block', 'expression' => '(ip.src eq 192.0.2.1)', 'description' => 'My own rule', 'enabled' => true, 'version' => '3', 'last_updated' => 'x'];
        Http::fake([
            "{$api}/zones/zone1/dns_records/rec1" => Http::response(['result' => ['id' => 'rec1']]),
            "{$api}/zones/zone1/rulesets/phases/http_request_firewall_custom/entrypoint" => fn (Request $request) => Http::response(['result' => ['rules' => $request->method() === 'GET' ? [$custom, ['description' => 'BuildPusher: shop.example.com', 'expression' => 'old']] : $request['rules']]]),
            "{$api}/zones/zone1/rulesets/phases/http_ratelimit/entrypoint" => fn (Request $request) => $request->method() === 'GET' ? Http::response(['errors' => []], 404) : Http::response(['result' => []]),
            "{$api}/zones/zone1/purge_cache" => Http::response(['success' => true]),
            "{$api}/zones*" => Http::response(['result' => [['id' => 'zone1', 'name' => 'example.com']]]),
        ]);
        $project = Project::factory()->withServices(['infrastructure'])->create();
        $owner = $this->ownerOf($project);
        $server = Server::factory()->create(['account_id' => $project->account_id, 'public_ip' => '203.0.113.10', 'provider_id' => Provider::factory()->create(['account_id' => $project->account_id])->id]);
        $website = Website::factory()->create(['server_id' => $server->id]);
        $cloudflare = Provider::factory()->type(ProviderType::Cloudflare)->create(['account_id' => $project->account_id]);
        $domain = new WebsiteDomain;
        $domain->forceFill(['website_id' => $website->id, 'hostname' => 'shop.example.com', 'type' => 'alias', 'dns_provider_id' => $cloudflare->id, 'dns_record_id' => 'zone1:rec1', 'dns_status' => 'active', 'ssl_status' => 'active'])->save();
        $url = "/projects/{$project->id}/infrastructure/websites/{$website->id}/domains/{$domain->id}/edge";

        $this->actingAs($owner)->get("/projects/{$project->id}/infrastructure/websites/{$website->id}?tab=domains")->assertOk()->assertSee(__('CDN and firewall'));
        $this->actingAs($owner)->put($url, ['blocked_countries' => 'Russia'])->assertSessionHasErrors('blocked_countries');
        $this->actingAs($owner)->put($url, ['blocked_ips' => '10.0.0.0/64'])->assertSessionHasErrors('blocked_ips');

        $this->actingAs($owner)->put($url, ['cdn_proxied' => '1', 'blocked_countries' => 'kp, ru', 'blocked_ips' => "203.0.113.7\n198.51.100.0/24", 'rate_limit_requests' => 50])->assertRedirect();
        $domain->refresh();
        $this->assertSame([true, ['KP', 'RU'], ['203.0.113.7', '198.51.100.0/24'], 50, null], [$domain->cdn_proxied, $domain->blocked_countries, $domain->blocked_ips, $domain->rate_limit_requests, $domain->edge_error]);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && str_ends_with($request->url(), '/dns_records/rec1') && $request['proxied'] === true);
        Http::assertSent(function (Request $request) use ($custom): bool {
            if ($request->method() !== 'PUT' || ! str_ends_with($request->url(), 'http_request_firewall_custom/entrypoint')) {
                return false;
            }
            $rules = $request['rules'];
            $this->assertCount(2, $rules, 'The zone’s own rule is kept and our old one replaced.');
            $this->assertSame(['id' => 'mine', 'action' => 'block', 'expression' => $custom['expression'], 'description' => 'My own rule', 'enabled' => true], $rules[0]);
            $this->assertSame('(http.host eq "shop.example.com") and (ip.geoip.country in {"KP" "RU"} or ip.src in {203.0.113.7 198.51.100.0/24})', $rules[1]['expression']);

            return true;
        });
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && str_ends_with($request->url(), 'http_ratelimit/entrypoint') && $request['rules'][0]['ratelimit']['requests_per_period'] === 50 && $request['rules'][0]['ratelimit']['period'] === 10);

        $build = Build::factory()->create(['website_id' => $website->id, 'status' => Build::STATUS_SUCCEEDED]);
        DeployFinished::dispatch($build);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/zones/zone1/purge_cache') && $request['hosts'] === ['shop.example.com']);
    }
}
