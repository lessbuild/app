<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Enums\AccountRole;
use App\Enums\AuditAction;
use App\Enums\ProviderType;
use App\Jobs\Deploy\ApplyEnvironmentRuntime;
use App\Models\AuditEntry;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class MaintenanceModeTest extends TestCase
{
    use InfrastructureHelpers, MonitoringHelpers {
        MonitoringHelpers::ownerOf insteadof InfrastructureHelpers;
    }
    use RefreshDatabase;

    /**
     * Check that maintenance mode goes on and off across an environment's websites, with a secret link for the team,
     * and that hibernation leaves it alone.
     *
     * @return void
     */
    public function test_an_environment_can_go_into_maintenance_mode_and_back(): void
    {
        $this->fakeInfrastructure();
        $project = Project::factory()->withServices(['deploy', 'infrastructure'])->create();
        $owner = $this->ownerOf($project);
        $environment = $project->environments()->where('slug', 'production')->firstOrFail();
        $website = Website::factory()->create(['server_id' => Server::factory()->create(['account_id' => $project->account_id])->id]);
        $git = Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $project->account_id]);
        Repository::factory()->create(['website_id' => $website->id, 'project_id' => $project->id, 'environment_id' => $environment->id, 'provider_id' => $git->id]);
        $url = "/api/app/projects/{$project->id}/deploy/environments/{$environment->id}/maintenance";

        $this->actingAs($owner)->putJson($url, ['down' => '1'])->assertSuccessful()->assertSuccessful();
        $environment->refresh();
        $this->assertNotNull($environment->maintenance_at);
        $secret = (string) $environment->maintenance_secret;
        $this->assertSame(24, strlen($secret));
        $down = collect($this->shell->ran)->pluck('command')->last();
        $this->assertStringContainsString('php artisan down --retry=60 --secret='.escapeshellarg($secret), (string) $down);
        $this->assertStringContainsString($website->deploymentPath('current'), (string) $down);
        $this->actingAs($owner)->getJson("/api/app/projects/{$project->id}/deploy/environments/{$environment->id}")->assertJsonPath('environment.maintenanceAt', fn (?string $at): bool => $at !== null)->assertJsonPath('environment.maintenanceSecret', $secret);

        $this->assertStringContainsString('if [ 1 = 0 ]', ApplyEnvironmentRuntime::script($website, false, 1, true), 'Waking from hibernation keeps maintenance mode on.');
        $this->assertStringContainsString('if [ 0 = 0 ]', ApplyEnvironmentRuntime::script($website, false, 1));

        $this->actingAs($owner)->putJson($url, ['down' => '0'])->assertSuccessful();
        $this->assertNull($environment->refresh()->maintenance_at);
        $this->assertStringContainsString('php artisan up', (string) collect($this->shell->ran)->pluck('command')->last());
        $this->assertSame(2, AuditEntry::query()->whereIn('action', [AuditAction::MaintenanceStarted, AuditAction::MaintenanceEnded])->count());

        $viewer = User::factory()->create();
        $this->addMember($project, $viewer, AccountRole::Viewer);
        $this->actingAs($viewer)->putJson($url, ['down' => '1'])->assertForbidden();
    }
}
