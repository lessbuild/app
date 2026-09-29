<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Enums\ProviderType;
use App\Models\Build;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\Website;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class BuildCacheTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check that deploys point package managers at the website's shared cache, that clearing moves to a fresh
     * version (removing the old one), and that turning it off leaves installs uncached.
     *
     * @return void
     */
    public function test_deploys_share_a_dependency_cache_that_can_be_cleared_or_turned_off(): void
    {
        $this->fakeInfrastructure();
        $this->withoutMiddleware(RequirePassword::class);
        $project = Project::factory()->withServices(['deploy', 'infrastructure'])->create();
        $owner = $this->ownerOf($project);
        $server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $project->account_id])->id]);
        $website = Website::factory()->create(['server_id' => $server->id, 'deployment_slug' => 'shop']);
        $github = Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $project->account_id]);
        $repository = Repository::factory()->create(['website_id' => $website->id, 'project_id' => $project->id, 'provider_id' => $github->id]);
        $base = "/projects/{$project->id}/deploy/repositories/{$repository->id}";
        $deploy = function () use ($owner, $base): string {
            Build::query()->update(['status' => Build::STATUS_SUCCEEDED]);
            $this->actingAs($owner)->post("{$base}/builds")->assertRedirect();

            $started = end($this->scripts->started);
            $this->assertNotFalse($started);

            return $started['script'];
        };

        $script = $deploy();
        $this->assertStringContainsString("BUILD_CACHE='/var/www/shop/cache/v1'", $script);
        $this->assertStringContainsString('export COMPOSER_CACHE_DIR="$BUILD_CACHE/composer" npm_config_cache="$BUILD_CACHE/npm"', $script);
        $this->assertLessThan(strpos($script, 'composer install'), strpos($script, 'COMPOSER_CACHE_DIR'));

        $this->actingAs($owner)->get("{$base}?tab=settings")->assertOk()->assertSee('Clear build cache');
        $this->actingAs($owner)->put("{$base}/build-cache", ['build_cache_enabled' => '1', 'clear' => '1'])->assertRedirect("{$base}?tab=settings");
        $script = $deploy();
        $this->assertStringContainsString("BUILD_CACHE='/var/www/shop/cache/v2'", $script);
        $this->assertStringContainsString("find '/var/www/shop/cache' -mindepth 1 -maxdepth 1 -type d ! -name 'v2' -exec rm -rf", $script);

        $this->actingAs($owner)->put("{$base}/build-cache", ['build_cache_enabled' => '0'])->assertRedirect();
        $this->assertStringNotContainsString('COMPOSER_CACHE_DIR', $deploy());
    }
}
