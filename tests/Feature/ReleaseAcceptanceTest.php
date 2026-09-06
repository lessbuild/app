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

        $this->artisan('buildpusher:acceptance:audit', [
            'project' => $project->id,
            '--provider' => Provider::TYPE_DIGITALOCEAN,
            '--since' => now()->subHour()->toIso8601String(),
            '--json' => true,
        ])
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
            'revision' => str_repeat('a', 40),
            'release_name' => 'release-a',
            'release_path' => '/var/www/acceptance/releases/release-a',
            'finished_at' => now()->subMinutes(40),
        ]);
        $repository->builds()->create([
            'environment_id' => $environment->id,
            'status' => Build::STATUS_SUCCEEDED,
            'trigger_source' => Build::TRIGGER_MANUAL,
            'revision' => str_repeat('b', 40),
            'release_name' => 'release-b',
            'release_path' => '/var/www/acceptance/releases/release-b',
            'finished_at' => now()->subMinutes(35),
        ]);
        $rollback = $repository->builds()->create([
            'environment_id' => $environment->id,
            'status' => Build::STATUS_SUCCEEDED,
            'trigger_source' => Build::TRIGGER_ROLLBACK,
            'rolled_back_from_build_id' => $deployment->id,
            'revision' => $deployment->revision,
            'release_name' => $deployment->release_name,
            'release_path' => $deployment->release_path,
            'finished_at' => now()->subMinutes(30),
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
            'last_verified_at' => now()->subMinutes(16),
        ]);
        $backup = $website->backups()->create([
            'backup_destination_id' => $destination->id,
            'status' => WebsiteBackup::STATUS_SUCCEEDED,
            'snapshot_id' => str_repeat('c', 40),
            'https_verified_at' => now()->subMinutes(15),
            'completed_at' => now()->subMinutes(15),
        ]);
        $restore = BackupRestore::query()->create([
            'website_backup_id' => $backup->id,
            'requested_by' => $website->user_id,
            'status' => BackupRestore::STATUS_SUCCEEDED,
            'completed_at' => now()->subMinutes(10),
        ]);
        $health = $website->healthChecks()->create([
            'successful' => true,
            'source' => WebsiteHealthCheck::SOURCE_MANUAL,
            'endpoint' => 'https://acceptance.example.com/health',
            'checked_at' => now()->subMinutes(5),
        ]);

        $this->artisan('buildpusher:acceptance:audit', [
            'project' => $project->id,
            '--provider' => Provider::TYPE_HETZNER,
            '--since' => now()->subHour()->toIso8601String(),
            '--json' => true,
        ])->expectsOutputToContain('"scope": "recorded_lifecycle_evidence"')
            ->assertSuccessful();

        $laterRollback = $rollback->replicate();
        $laterRollback->finished_at = now()->subMinute();
        $laterRollback->save();
        $this->artisan('buildpusher:acceptance:audit', [
            'project' => $project->id,
            '--provider' => Provider::TYPE_HETZNER,
            '--since' => now()->subHour()->toIso8601String(),
            '--json' => true,
        ])->expectsOutputToContain('"status": "passed"')->assertSuccessful();
        $laterRollback->delete();

        $unusedBackup = $website->backups()->create([
            'backup_destination_id' => $destination->id,
            'status' => WebsiteBackup::STATUS_SUCCEEDED,
            'snapshot_id' => str_repeat('d', 40),
            'https_verified_at' => now()->subMinutes(16),
            'completed_at' => now()->subMinutes(16),
        ]);
        $this->artisan('buildpusher:acceptance:audit', [
            'project' => $project->id,
            '--provider' => Provider::TYPE_HETZNER,
            '--since' => now()->subHour()->toIso8601String(),
            '--json' => true,
        ])->expectsOutputToContain('"status": "passed"')->assertSuccessful();
        $unusedBackup->delete();

        $foreignOwner = User::factory()->create();
        foreach ([$repository, $destination, $website, $website->server, $website->server->provider] as $resource) {
            $organizationId = $resource->organization_id;
            $resource->forceFill(['organization_id' => $foreignOwner->current_organization_id])->save();
            $this->artisan('buildpusher:acceptance:audit', [
                'project' => $project->id,
                '--provider' => Provider::TYPE_HETZNER,
                '--since' => now()->subHour()->toIso8601String(),
                '--json' => true,
            ])->expectsOutputToContain('"status": "incomplete"')->assertFailed();
            $resource->forceFill(['organization_id' => $organizationId])->save();
        }

        $originalServer = $environment->server_id;
        $environment->update(['server_id' => null]);
        $this->artisan('buildpusher:acceptance:audit', [
            'project' => $project->id,
            '--provider' => Provider::TYPE_HETZNER,
            '--since' => now()->subHour()->toIso8601String(),
            '--json' => true,
        ])->expectsOutputToContain('"status": "incomplete"')->assertFailed();
        $environment->update(['server_id' => $originalServer]);

        foreach (['release_name' => 'different-release', 'release_path' => '/different/artifact'] as $field => $value) {
            $original = $rollback->{$field};
            $rollback->update([$field => $value]);
            $this->artisan('buildpusher:acceptance:audit', [
                'project' => $project->id,
                '--provider' => Provider::TYPE_HETZNER,
                '--since' => now()->subHour()->toIso8601String(),
                '--json' => true,
            ])->expectsOutputToContain('"status": "incomplete"')->assertFailed();
            $rollback->update([$field => $original]);
        }

        $health->update(['checked_at' => now()->subMinutes(11)]);
        $this->artisan('buildpusher:acceptance:audit', [
            'project' => $project->id,
            '--provider' => Provider::TYPE_HETZNER,
            '--since' => now()->subHour()->toIso8601String(),
            '--json' => true,
        ])->expectsOutputToContain('"status": "incomplete"')->assertFailed();
        $health->update(['checked_at' => now()->subMinutes(5)]);

        $restore->update(['completed_at' => now()->subMinutes(16)]);
        $this->artisan('buildpusher:acceptance:audit', [
            'project' => $project->id,
            '--provider' => Provider::TYPE_HETZNER,
            '--since' => now()->subHour()->toIso8601String(),
            '--json' => true,
        ])->expectsOutputToContain('"status": "incomplete"')->assertFailed();
        $restore->update(['completed_at' => now()->subMinutes(10)]);

        $backup->update(['https_verified_at' => null]);
        $this->artisan('buildpusher:acceptance:audit', [
            'project' => $project->id,
            '--provider' => Provider::TYPE_HETZNER,
            '--since' => now()->subHour()->toIso8601String(),
            '--json' => true,
        ])->expectsOutputToContain('"status": "incomplete"')->assertFailed();
        $backup->update(['https_verified_at' => now()->subMinutes(15)]);
        $destination->update(['last_verified_at' => now()]);
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

    public function test_audit_rejects_unrelated_or_out_of_order_lifecycle_rows(): void
    {
        Carbon::setTestNow('2026-09-05 12:00:00');
        [$project, $website, $environment] = $this->acceptanceInfrastructure();
        $provider = $website->user->providers()->create([
            'name' => 'GitHub', 'description' => 'Acceptance source',
            'provider' => Provider::TYPE_GITHUB, 'token' => 'source-token',
        ]);
        $repository = $website->repositories()->create([
            'user_id' => $website->user_id,
            'provider_id' => $provider->id,
            'name' => 'Acceptance app',
            'url' => 'github.com/example/acceptance.git',
            'description' => 'Disposable acceptance repository',
        ]);
        $initial = $repository->builds()->create([
            'environment_id' => $environment->id,
            'status' => Build::STATUS_SUCCEEDED,
            'trigger_source' => Build::TRIGGER_MANUAL,
            'revision' => str_repeat('a', 40),
            'release_name' => 'release-a',
            'release_path' => '/var/www/acceptance/releases/release-a',
            'finished_at' => now()->subMinutes(40),
        ]);
        $repository->builds()->create([
            'environment_id' => $environment->id,
            'status' => Build::STATUS_SUCCEEDED,
            'trigger_source' => Build::TRIGGER_ROLLBACK,
            'rolled_back_from_build_id' => $initial->id,
            'revision' => $initial->revision,
            'release_name' => 'release-a',
            'release_path' => '/var/www/acceptance/releases/release-a',
            'finished_at' => now()->subMinutes(30),
        ]);

        $this->artisan('buildpusher:acceptance:audit', [
            'project' => $project->id,
            '--provider' => Provider::TYPE_HETZNER,
            '--since' => now()->subHour()->toIso8601String(),
            '--json' => true,
        ])->expectsOutputToContain('"status": "incomplete"')->assertFailed();

        $repository->builds()->create([
            'environment_id' => $environment->id,
            'status' => Build::STATUS_SUCCEEDED,
            'trigger_source' => Build::TRIGGER_MANUAL,
            'revision' => str_repeat('b', 40),
            'finished_at' => now()->subMinutes(20),
        ]);

        $this->artisan('buildpusher:acceptance:audit', [
            'project' => $project->id,
            '--provider' => Provider::TYPE_HETZNER,
            '--since' => now()->subHour()->toIso8601String(),
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
            ->expectsOutputToContain('Provider must be one of')
            ->assertExitCode(2);
        $this->artisan('buildpusher:acceptance:audit', [
            'project' => $project->id,
            '--provider' => Provider::TYPE_HETZNER,
            '--since' => 'tomorrow',
        ])
            ->expectsOutputToContain('Since must be a valid ISO-8601')
            ->assertExitCode(2);
        $this->artisan('buildpusher:acceptance:audit', [
            'project' => $project->id,
            '--provider' => Provider::TYPE_HETZNER,
        ])->expectsOutputToContain('Since must be a valid ISO-8601')->assertExitCode(2);
        $this->artisan('buildpusher:acceptance:audit', [
            'project' => 999999,
            '--provider' => Provider::TYPE_HETZNER,
            '--since' => now()->toIso8601String(),
        ])->expectsOutputToContain('Project must identify an existing project')->assertExitCode(2);
    }

    public function test_invalid_calendar_dates_cannot_be_normalized_into_acceptance_cutoffs(): void
    {
        [$project] = $this->acceptanceInfrastructure();
        foreach (['2026-02-30T12:00:00Z', '2026-09-06T24:00:00Z', '2026-09-06T12:00:60Z'] as $since) {
            $this->artisan('buildpusher:acceptance:audit', [
                'project' => $project->id,
                '--provider' => Provider::TYPE_HETZNER,
                '--since' => $since,
            ])->expectsOutputToContain('Since must be a valid ISO-8601')->assertExitCode(2);
        }
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
