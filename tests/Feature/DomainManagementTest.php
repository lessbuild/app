<?php

namespace Tests\Feature;

use App\Jobs\ApplyWebsiteDomainsJob;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\ManagedSsh;
use App\Services\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DomainManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_cloudflare_alias_is_created_without_exposing_the_token(): void
    {
        Queue::fake();
        [$owner, $website, $provider] = $this->infrastructure();
        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records' => Http::response(['success' => true, 'result' => ['id' => 'record-1']]),
            'api.cloudflare.com/client/v4/zones*' => Http::response(['success' => true, 'result' => [
                ['id' => 'zone-1', 'name' => 'example.com', 'status' => 'active'],
            ]]),
        ]);

        $this->actingAs($owner)->post(route('domains.store'), [
            'website_id' => $website->id,
            'hostname' => 'WWW.Example.com.',
            'type' => 'alias',
            'dns_provider_id' => $provider->id,
        ])->assertRedirect()->assertSessionHas('success');

        $domain = $website->domains()->where('type', 'alias')->sole();
        $this->assertSame('www.example.com', $domain->hostname);
        $this->assertSame('active', $domain->dns_status);
        $this->assertSame('zone-1:record-1', $domain->getRawOriginal('dns_record_id'));
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records'
            && $request['type'] === 'A'
            && $request['content'] === '203.0.113.10'
            && $request->hasHeader('Authorization', 'Bearer cloudflare-secret'));
        Queue::assertPushed(ApplyWebsiteDomainsJob::class, fn ($job): bool => $job->websiteId === $website->id);
        $this->actingAs($owner)->get(route('domains.index'))->assertOk()->assertSee('www.example.com')->assertDontSee('cloudflare-secret');
    }

    public function test_temporary_domains_require_operator_configuration_and_cloudflare(): void
    {
        Queue::fake();
        [$owner, $website, $provider] = $this->infrastructure();
        config(['domains.temporary_base_domain' => null]);
        $this->actingAs($owner)->post(route('domains.temporary'), [
            'website_id' => $website->id, 'dns_provider_id' => $provider->id,
        ])->assertSessionHasErrors('domain');

        config(['domains.temporary_base_domain' => 'apps.buildpusher.com']);
        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-2/dns_records' => Http::response(['result' => ['id' => 'record-2']]),
            'api.cloudflare.com/client/v4/zones*' => Http::response(['result' => [['id' => 'zone-2', 'name' => 'buildpusher.com']]]),
        ]);
        $this->actingAs($owner)->post(route('domains.temporary'), [
            'website_id' => $website->id, 'dns_provider_id' => $provider->id,
        ])->assertRedirect()->assertSessionHas('success');
        $domain = $website->domains()->where('is_temporary', true)->sole();
        $this->assertStringEndsWith('.apps.buildpusher.com', $domain->hostname);
    }

    public function test_domain_routing_preserves_aliases_redirects_and_non_php_runtime(): void
    {
        [$owner, $website] = $this->infrastructure();
        $website->domains()->create(['created_by' => $owner->id, 'hostname' => 'www.example.com', 'type' => 'alias']);
        $website->domains()->create(['created_by' => $owner->id, 'hostname' => 'old.example.com', 'type' => 'redirect', 'redirect_url' => 'https://example.com']);
        $project = $owner->currentOrganization->projects()->create(['created_by' => $owner->id, 'name' => 'App', 'slug' => 'app', 'preset' => 'nextjs']);
        $environment = $project->environments()->create([
            'website_id' => $website->id, 'server_id' => $website->server_id,
            'name' => 'Production', 'slug' => 'production', 'type' => 'production', 'runtime_type' => 'node',
        ]);
        $environment->builds()->create(['repository_id' => $owner->repositories()->create([
            'provider_id' => $owner->providers()->create(['name' => 'GitHub', 'description' => 'Source', 'provider' => Provider::TYPE_GITHUB, 'token' => 'source'])->id,
            'website_id' => $website->id, 'name' => 'App', 'description' => 'App', 'url' => 'github.com/example/app.git', 'branch' => 'main',
        ])->id, 'status' => 'succeeded']);
        $command = '';

        (new ApplyWebsiteDomainsJob($website->id))->handle($this->runner($command));

        preg_match("/printf '%s' '([^']+)' \| base64 --decode/", $command, $matches);
        $config = base64_decode($matches[1] ?? '', true) ?: '';
        $this->assertStringContainsString('example.com, www.example.com', $config);
        $this->assertStringContainsString('reverse_proxy 127.0.0.1:', $config);
        $this->assertStringContainsString('old.example.com', $config);
        $this->assertStringContainsString('redir https://example.com{uri} permanent', $config);
    }

    private function infrastructure(): array
    {
        $owner = User::factory()->create();
        $server = $owner->servers()->create([
            'name' => 'Production', 'public_ip' => '203.0.113.10', 'ssh_private_key' => 'private',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $owner->websites()->create([
            'server_id' => $server->id, 'name' => 'Application', 'description' => 'Website',
            'environment' => '', 'url' => 'example.com', 'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $provider = $owner->providers()->create([
            'name' => 'Cloudflare', 'description' => 'DNS', 'provider' => Provider::TYPE_CLOUDFLARE,
            'token' => 'cloudflare-secret',
        ]);

        return [$owner, $website, $provider];
    }

    private function runner(string &$command): Runner
    {
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('isSuccessful')->once()->andReturnTrue();
        $process->shouldReceive('getErrorOutput')->zeroOrMoreTimes()->andReturn('');
        $ssh = Mockery::mock(ManagedSsh::class);
        $ssh->shouldReceive('execute')->once()->with(Mockery::on(function (string $value) use (&$command): bool {
            $command = $value;

            return true;
        }))->andReturn($process);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->andReturnSelf();
        $runner->shouldReceive('create')->once()->andReturn($ssh);

        return $runner;
    }
}
