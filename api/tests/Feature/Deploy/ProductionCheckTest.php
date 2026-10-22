<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Enums\ProviderType;
use App\Enums\ServerType;
use App\Models\Build;
use App\Models\Environment;
use App\Models\EnvironmentProcess;
use App\Models\EnvironmentResource;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\User;
use App\Models\Website;
use App\Services\Deploy\ProductionCheck;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class ProductionCheckTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private Environment $production;

    private Server $server;

    private string $base;

    /**
     * A Laravel app in production on a 512 MB DigitalOcean server, with the settings vacenly.com started with.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $this->project = Project::factory()->withServices(['deploy', 'infrastructure'])->create();
        $this->owner = $this->ownerOf($this->project);
        $this->onTier($this->project, 'deploy', 'pro');
        $provider = Provider::factory()->type(ProviderType::DigitalOcean)->create(['account_id' => $this->project->account_id]);
        Cache::put('rightsizing.sizes.'.$provider->id, [
            ['slug' => 's-1vcpu-512mb-10gb', 'vcpus' => 1, 'memory' => 512, 'price_monthly' => 4],
            ['slug' => 's-1vcpu-1gb', 'vcpus' => 1, 'memory' => 1024, 'price_monthly' => 6],
            ['slug' => 's-1vcpu-2gb', 'vcpus' => 1, 'memory' => 2048, 'price_monthly' => 12],
        ], now()->addHour());
        $this->server = Server::factory()->create(['account_id' => $this->project->account_id, 'provider_id' => $provider->id, 'type' => ServerType::App, 'size' => 's-1vcpu-512mb-10gb', 'name' => 'vacenly']);
        $website = Website::factory()->create(['account_id' => $this->project->account_id, 'server_id' => $this->server->id, 'deployment_slug' => 'api', 'url' => 'api.example.com']);
        $this->production = $this->project->environments()->where('slug', 'production')->firstOrFail();
        $this->production->forceFill(['runtime_type' => 'php', 'kind' => 'production'])->save();
        Repository::factory()->create(['project_id' => $this->project->id, 'website_id' => $website->id, 'environment_id' => $this->production->id,
            'provider_id' => Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $this->project->account_id])->id]);
        foreach (['APP_KEY' => 'base64:abc', 'APP_DEBUG' => 'true', 'APP_URL' => 'http://example.com/', 'MAIL_MAILER' => 'resend'] as $key => $value) {
            (new EnvironmentVariable)->forceFill(['environment_id' => $this->production->id, 'key' => $key, 'value' => $value, 'is_secret' => $key === 'APP_KEY', 'scope' => 'runtime', 'current_version' => 1])->save();
        }
        $this->base = "/api/app/projects/{$this->project->id}/deploy/environments/{$this->production->id}";
        $this->withoutMiddleware(RequirePassword::class);
    }

    /**
     * The environment page lists what isn't right for production, most serious first; Fix applies one fix and Fix all
     * the rest, through the usual versioned variable and worker changes, and the page is then clear of them.
     *
     * @return void
     */
    public function test_the_page_lists_issues_and_fixes_them(): void
    {
        $issues = collect((array) $this->actingAs($this->owner)->getJson($this->base)->assertOk()->json('productionCheck'))->keyBy('key');

        $memory = "server-{$this->server->id}-memory";
        $this->assertSame(['app-debug', 'app-url', 'log-level', 'queue-worker', $memory, 'log-stack', 'cache_store', 'session_driver'], $issues->keys()->all());
        $this->assertSame('critical', $issues['app-debug']['severity']);
        $this->assertSame('APP_URL should be https://example.com', $issues['app-url']['title']);
        $this->assertSame('Use file for sessions', $issues['session_driver']['fixLabel']);
        $this->assertTrue($issues['queue-worker']['fixable']);

        $this->actingAs($this->owner)->postJson("{$this->base}/production-check", ['key' => 'app-debug'])->assertOk()->assertJsonPath('message', 'Fixed 1 issue. It applies with the next deploy.');
        $debug = EnvironmentVariable::query()->where('environment_id', $this->production->id)->where('key', 'APP_DEBUG')->sole();
        $this->assertSame(['false', 2], [$debug->value, $debug->current_version], 'A versioned change, like one made by hand.');

        $this->actingAs($this->owner)->postJson("{$this->base}/production-check")->assertOk()->assertJsonPath('message', 'Fixed 6 issues. They apply with the next deploy.');
        $values = EnvironmentVariable::query()->where('environment_id', $this->production->id)->pluck('value', 'key')->all();
        $this->assertSame(['https://example.com', 'warning', 'daily', 'file', 'file'], [$values['APP_URL'], $values['LOG_LEVEL'], $values['LOG_STACK'], $values['CACHE_STORE'], $values['SESSION_DRIVER']]);
        $this->assertSame('php artisan queue:work --tries=3 --max-time=3600', EnvironmentProcess::query()->where('environment_id', $this->production->id)->sole()->command);
        $this->assertSame([$memory], array_column($this->actingAs($this->owner)->getJson($this->base)->json('productionCheck'), 'key'), 'Only the server size is left, which needs a person.');
        $this->actingAs($this->owner)->postJson("{$this->base}/production-check")->assertJsonPath('message', 'Nothing needed fixing.');
    }

    /**
     * With Redis in the environment, cache and sessions are pointed at it; a non-production environment isn't held to
     * production's logging and debug rules; and a site that isn't Laravel isn't judged by Laravel's settings.
     *
     * @return void
     */
    public function test_suggestions_fit_the_environment(): void
    {
        (new EnvironmentResource)->forceFill(['environment_id' => $this->production->id, 'name' => 'cache', 'type' => 'redis', 'is_managed' => true, 'status' => 'ready', 'configuration' => ['variables' => ['REDIS_HOST' => '127.0.0.1']]])->save();
        $this->production->refresh();
        $keys = fn (): array => array_values(array_filter(array_map(fn ($issue): string => $issue->key, app(ProductionCheck::class)->issues($this->production->fresh() ?? $this->production)), fn (string $key): bool => ! str_starts_with($key, 'server-')));
        $this->assertSame(['variables' => ['CACHE_STORE' => 'redis']], ['variables' => collect(app(ProductionCheck::class)->issues($this->production))->firstWhere('key', 'cache_store')?->variables]);

        $this->production->forceFill(['kind' => 'staging'])->save();
        $this->assertSame(['app-url', 'queue-worker', 'cache_store', 'session_driver'], $keys(), 'Staging keeps debug logging; only a trailing slash is still wrong.');

        EnvironmentVariable::query()->where('environment_id', $this->production->id)->where('key', 'APP_KEY')->delete();
        $this->assertSame([], $keys(), 'Without Laravel’s settings, nothing Laravel-specific is suggested.');
    }

    /**
     * A full disk and a server too small for what runs on it are flagged with what would fit and its price, and the
     * deploy log starts with the check.
     *
     * @return void
     */
    public function test_servers_and_deploys(): void
    {
        (new ServerMetric)->forceFill(['server_id' => $this->server->id, 'load_1m' => 0.9, 'load_5m' => 0.7, 'load_15m' => 0.5, 'cpu_percent' => 31, 'memory_percent' => 64, 'disk_percent' => 96, 'network_rx_bytes' => 0, 'network_tx_bytes' => 0, 'disk_read_bytes' => 0, 'disk_write_bytes' => 0, 'process_count' => 114, 'uptime_seconds' => 3600, 'recorded_at' => now()])->save();
        (new EnvironmentProcess)->forceFill(['environment_id' => $this->production->id, 'name' => 'queue', 'type' => 'worker', 'command' => 'php artisan queue:work', 'replicas' => 1, 'restart_policy' => 'always', 'restart_delay_seconds' => 5, 'is_enabled' => true])->save();
        $issues = collect(app(ProductionCheck::class)->issues($this->production))->keyBy('key')->all();

        $disk = $issues["server-{$this->server->id}-disk"];
        $this->assertSame(['critical', 'vacenly’s disk is 96% full'], [$disk->severity, $disk->title]);
        $memory = $issues["server-{$this->server->id}-memory"];
        $this->assertSame('vacenly is too small for what runs on it', $memory->title);
        $this->assertStringContainsString('It has 0.5 GB of memory and what runs on it needs about 1.1 GB', $memory->detail);
        $this->assertStringContainsString('s-1vcpu-2gb (2 GB) fits, for $12.00 a month.', $memory->detail);
        $this->assertSame("/projects/{$this->project->id}/infrastructure/servers/{$this->server->id}", $memory->url);
        $this->assertFalse($memory->fixable());

        $this->actingAs($this->owner)->postJson("/api/app/projects/{$this->project->id}/deploy/repositories/".Repository::query()->sole()->id.'/builds')->assertSuccessful();
        $script = $this->scripts->started[0]['script'];
        $this->assertStringContainsString("echo 'Production check: 8 things to look at (environment page → Production check)'", $script);
        $this->assertStringContainsString("echo '  - Debug mode is on'", $script);
        $this->assertNotNull(Build::query()->first());
    }
}
