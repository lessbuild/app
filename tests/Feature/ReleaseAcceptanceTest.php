<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Models\BackupRestore;
use App\Models\Build;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteBackup;
use App\Models\WebsiteHealthCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReleaseAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_fails_safely_when_real_lifecycle_evidence_is_missing(): void
    {
        $user = User::factory()->create();
        $project = Project::query()->create([
            'organization_id' => $user->current_organization_id,
            'created_by' => $user->id,
            'name' => 'Acceptance',
            'slug' => 'acceptance',
        ]);

        $this->artisan('buildpusher:acceptance:audit', ['project' => $project->id, '--json' => true])
            ->expectsOutputToContain('Required acceptance evidence is missing')
            ->assertFailed();
    }

    public function test_audit_requires_one_provider_environment_and_fresh_complete_evidence(): void
    {
        Carbon::setTestNow('2026-09-05 12:00:00');
        [$project, $website, $environment] = $this->acceptanceInfrastructure();
        $repository = $website->repositories()->create([
            'user_id' => $website->user_id,
            'provider_id' => $website->user->providers()->where('provider', Provider::TYPE_GITHUB)->value('id'),
            'name' => 'Acceptance app',
            'url' => 'github.com/example/acceptance.git',
            'description' => 'Disposable acceptance repository',
        ]);
        $deployment = $repository->builds()->create([
            'environment_id' => $environment->id,
            'status' => Build::STATUS_SUCCEEDED,
            'trigger_source' => Build::TRIGGER_MANUAL,
            'finished_at' => now()->subMinutes(40),
        ]);
        $rollback = $repository->builds()->create([
            'environment_id' => $environment->id,
            'status' => Build::STATUS_SUCCEEDED,
            'trigger_source' => Build::TRIGGER_ROLLBACK,
            'rolled_back_from_build_id' => $deployment->id,
            'finished_at' => now()->subMinutes(30),
        ]);
        $website->healthChecks()->create([
            'successful' => true,
            'source' => WebsiteHealthCheck::SOURCE_MANUAL,
            'endpoint' => 'https://acceptance.example.com/health',
            'checked_at' => now()->subMinutes(20),
        ]);
        $destination = BackupDestination::query()->create([
            'organization_id' => $project->organization_id,
            'created_by' => $website->user_id,
            'name' => 'Disposable offsite',
            'endpoint' => 'https://objects.example.com',
            'bucket' => 'acceptance',
            'access_key' => 'access',
            'secret_key' => 'secret',
            'repository_password' => 'repository-secret',
            'last_verified_at' => now(),
        ]);
        $backup = $website->backups()->create([
            'backup_destination_id' => $destination->id,
            'status' => WebsiteBackup::STATUS_SUCCEEDED,
            'snapshot_id' => 'snapshot-1',
            'completed_at' => now()->subMinutes(15),
        ]);
        BackupRestore::query()->create([
            'website_backup_id' => $backup->id,
            'requested_by' => $website->user_id,
            'status' => BackupRestore::STATUS_SUCCEEDED,
            'completed_at' => now()->subMinutes(10),
        ]);

        $this->artisan('buildpusher:acceptance:audit', [
            'project' => $project->id,
            '--provider' => Provider::TYPE_HETZNER,
            '--since' => now()->subHour()->toIso8601String(),
            '--json' => true,
        ])->expectsOutputToContain('"status": "passed"')->assertSuccessful();

        $this->artisan('buildpusher:acceptance:audit', [
            'project' => $project->id,
            '--provider' => Provider::TYPE_VULTR,
            '--since' => now()->subHour()->toIso8601String(),
            '--json' => true,
        ])->expectsOutputToContain('"status": "incomplete"')->assertFailed();

        $this->artisan('buildpusher:acceptance:audit', [
            'project' => $project->id,
            '--provider' => Provider::TYPE_HETZNER,
            '--since' => now()->subMinutes(5)->toIso8601String(),
            '--json' => true,
        ])->expectsOutputToContain('"status": "incomplete"')->assertFailed();
    }

    public function test_audit_rejects_invalid_filters(): void
    {
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create([
            'created_by' => $user->id, 'name' => 'Acceptance', 'slug' => 'acceptance',
        ]);

        $this->artisan('buildpusher:acceptance:audit', ['project' => $project->id, '--provider' => 'unknown'])
            ->expectsOutputToContain('Provider must be one of')
            ->assertExitCode(2);
        $this->artisan('buildpusher:acceptance:audit', ['project' => $project->id, '--since' => 'not-a-date'])
            ->expectsOutputToContain('Since must be a valid ISO-8601')
            ->assertExitCode(2);
        $this->artisan('buildpusher:acceptance:audit', ['project' => $project->id, '--since' => 'tomorrow'])
            ->expectsOutputToContain('Since must be a valid ISO-8601')
            ->assertExitCode(2);
    }

    /** @return array{Project, Website, Environment} */
    private function acceptanceInfrastructure(): array
    {
        $user = User::factory()->create();
        $cloud = $user->providers()->create([
            'name' => 'Hetzner', 'description' => 'Acceptance cloud',
            'provider' => Provider::TYPE_HETZNER, 'token' => 'cloud-token',
        ]);
        $user->providers()->create([
            'name' => 'GitHub', 'description' => 'Acceptance source',
            'provider' => Provider::TYPE_GITHUB, 'token' => 'source-token',
        ]);
        $server = $user->servers()->create([
            'provider_id' => $cloud->id,
            'identifier' => 'server-123',
            'name' => 'Acceptance server',
            'provisioning_status' => Server::STATUS_ACTIVE,
            'provisioned_at' => now()->subMinutes(55),
        ]);
        $website = $user->websites()->create([
            'server_id' => $server->id,
            'name' => 'Acceptance website',
            'description' => 'Disposable website',
            'environment' => '',
            'url' => 'acceptance.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
            'provisioned_at' => now()->subMinutes(50),
            'health_check_enabled' => true,
            'health_status' => Website::HEALTH_HEALTHY,
        ]);
        $project = $user->currentOrganization->projects()->create([
            'created_by' => $user->id, 'name' => 'Acceptance', 'slug' => 'acceptance',
        ]);
        $environment = $project->environments()->create([
            'server_id' => $server->id,
            'website_id' => $website->id,
            'name' => 'Staging',
            'slug' => 'staging',
            'type' => 'staging',
        ]);

        return [$project, $website, $environment];
    }
}
