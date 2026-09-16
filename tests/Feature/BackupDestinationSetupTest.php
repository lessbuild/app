<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteBackup;
use App\Services\ManagedSsh;
use App\Services\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BackupDestinationSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.enforce_entitlements' => false]);
    }

    public function test_backup_page_explains_spaces_setup_and_offers_presets(): void
    {
        [$owner] = $this->infrastructure();

        $response = $this->actingAs($owner)->get(route('backups.index'));

        $response->assertOk()
            ->assertSee('DigitalOcean Spaces')
            ->assertSee('A DigitalOcean control-plane token is different.')
            ->assertSee('https://&lt;region&gt;.digitaloceanspaces.com', false)
            ->assertViewHas('destinationPresets');
    }

    public function test_spaces_preset_derives_its_endpoint_without_persisting_form_metadata(): void
    {
        [$owner] = $this->infrastructure();

        $this->actingAs($owner)->post(route('backups.destinations.store'), [
            'storage_provider' => 'digitalocean_spaces',
            'name' => 'London backups',
            'endpoint' => '',
            'bucket' => 'buildpusher-backups',
            'region' => 'lon1',
            'access_key' => 'spaces-access',
            'secret_key' => 'spaces-secret',
            'path_prefix' => 'buildpusher',
        ])->assertRedirect();

        $destination = BackupDestination::query()->sole();
        $this->assertSame('https://lon1.digitaloceanspaces.com', $destination->endpoint);
        $this->assertArrayNotHasKey('storage_provider', $destination->getAttributes());
        $this->assertSame('spaces-access', $destination->access_key);
        $this->assertNotSame('spaces-access', DB::table('backup_destinations')->value('access_key'));
    }

    public function test_invalid_destination_credentials_are_not_flashed_on_validation_failure(): void
    {
        [$owner] = $this->infrastructure();

        $response = $this->from(route('backups.index'))->actingAs($owner)->post(route('backups.destinations.store'), [
            'storage_provider' => 'digitalocean_spaces',
            'name' => '',
            'endpoint' => '',
            'bucket' => '',
            'region' => 'lon1',
            'access_key' => 'do-not-flash-access',
            'secret_key' => 'do-not-flash-secret',
            'path_prefix' => 'buildpusher',
        ]);

        $response->assertRedirect(route('backups.index'));
        $this->assertSame('', (string) session()->get('_old_input.access_key', ''));
        $this->assertSame('', (string) session()->get('_old_input.secret_key', ''));
    }

    public function test_update_rotates_credentials_without_requiring_old_values(): void
    {
        [$owner] = $this->infrastructure();
        $destination = $this->destination($owner);
        $destination->update(['last_verified_at' => now(), 'last_error' => 'Old connection error']);

        $this->actingAs($owner)->patch(route('backups.destinations.update', $destination), [
            'storage_provider' => 'digitalocean_spaces',
            'name' => $destination->name,
            'endpoint' => $destination->endpoint,
            'bucket' => $destination->bucket,
            'region' => $destination->region,
            'access_key' => 'new-access',
            'secret_key' => '',
            'path_prefix' => $destination->path_prefix,
        ])->assertSessionHas('success', 'Backup destination updated. Verify it before the next backup.');

        $updated = $destination->fresh();
        $this->assertSame('new-access', $updated->access_key);
        $this->assertSame('secret-key', $updated->secret_key);
        $this->assertSame('repository-secret', $updated->repository_password);
        $this->assertNull($updated->last_verified_at);
        $this->assertNull($updated->last_error);
    }

    public function test_update_cannot_move_a_destination_with_retained_snapshots(): void
    {
        [$owner, $website] = $this->infrastructure();
        $destination = $this->destination($owner);
        $website->backups()->create([
            'backup_destination_id' => $destination->id,
            'status' => WebsiteBackup::STATUS_SUCCEEDED,
            'snapshot_id' => 'abcdef1234567890',
        ]);

        $this->actingAs($owner)->patch(route('backups.destinations.update', $destination), [
            'storage_provider' => 'digitalocean_spaces',
            'name' => $destination->name,
            'endpoint' => $destination->endpoint,
            'bucket' => 'different-bucket',
            'region' => $destination->region,
            'access_key' => '',
            'secret_key' => '',
            'path_prefix' => $destination->path_prefix,
        ])->assertSessionHas('error', 'Create a new destination instead of moving one that contains retained snapshots.');

        $this->assertSame('buildpusher-backups', $destination->fresh()->bucket);
    }

    public function test_connection_test_initializes_and_records_a_destination(): void
    {
        [$owner, $website] = $this->infrastructure();
        $destination = $this->destination($owner);
        $command = '';
        $this->app->instance(Runner::class, $this->runner($command));

        $this->actingAs($owner)->post(route('backups.destinations.test', $destination), [
            'website_id' => $website->id,
        ])->assertSessionHas('success', 'Backup destination verified and ready for backups.');

        $this->assertNotNull($destination->fresh()->last_verified_at);
        $this->assertNull($destination->fresh()->last_error);
        $this->assertStringContainsString('restic init', $command);
        $this->assertStringContainsString('restic snapshots --json', $command);
    }

    public function test_connection_failure_is_sanitized_and_recorded(): void
    {
        [$owner, $website] = $this->infrastructure();
        $destination = $this->destination($owner);
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('isSuccessful')->once()->andReturnFalse();
        $process->shouldReceive('getErrorOutput')->once()->andReturn('AWS_SECRET_ACCESS_KEY=secret-key');
        $process->shouldReceive('getOutput')->zeroOrMoreTimes()->andReturn('');
        $ssh = Mockery::mock(ManagedSsh::class);
        $ssh->shouldReceive('execute')->once()->andReturn($process);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->andReturnSelf();
        $runner->shouldReceive('create')->once()->andReturn($ssh);
        $this->app->instance(Runner::class, $runner);

        $this->actingAs($owner)->post(route('backups.destinations.test', $destination), [
            'website_id' => $website->id,
        ])->assertSessionHas('error', function (string $message): bool {
            return str_contains($message, '[redacted]') && ! str_contains($message, 'secret-key');
        });

        $updated = $destination->fresh();
        $this->assertStringContainsString('[redacted]', (string) $updated->last_error);
        $this->assertStringNotContainsString('secret-key', (string) $updated->last_error);
        $this->assertNull($updated->last_verified_at);
    }

    public function test_non_manager_cannot_edit_or_test_a_destination(): void
    {
        [$owner, $website] = $this->infrastructure();
        $destination = $this->destination($owner);
        $developer = User::factory()->create(['current_organization_id' => $owner->current_organization_id]);
        $owner->currentOrganization->members()->attach($developer->id, ['role' => 'developer']);

        $this->actingAs($developer)->patch(route('backups.destinations.update', $destination), [
            'storage_provider' => 'digitalocean_spaces',
            'name' => 'Changed',
            'endpoint' => $destination->endpoint,
            'bucket' => $destination->bucket,
            'region' => $destination->region,
            'path_prefix' => $destination->path_prefix,
        ])->assertForbidden();
        $this->actingAs($developer)->post(route('backups.destinations.test', $destination), [
            'website_id' => $website->id,
        ])->assertForbidden();

        $this->assertSame('Spaces', $destination->fresh()->name);
    }

    /** @return array{User, Website} */
    private function infrastructure(): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'DigitalOcean', 'provider' => Provider::TYPE_DIGITALOCEAN,
            'token' => 'token', 'description' => 'Cloud',
        ]);
        $server = $owner->servers()->create([
            'provider_id' => $provider->id, 'name' => 'Production', 'public_ip' => '203.0.113.20',
            'ssh_private_key' => 'private', 'mysql_root_password' => 'mysql-secret',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $owner->websites()->create([
            'server_id' => $server->id, 'name' => 'Application', 'description' => 'Website',
            'environment' => 'APP_KEY=secret', 'url' => 'app.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);

        return [$owner, $website];
    }

    private function destination(User $owner): BackupDestination
    {
        return $owner->currentOrganization->backupDestinations()->create([
            'created_by' => $owner->id,
            'name' => 'Spaces', 'endpoint' => 'https://lon1.digitaloceanspaces.com', 'bucket' => 'buildpusher-backups',
            'region' => 'lon1', 'access_key' => 'access-key', 'secret_key' => 'secret-key',
            'repository_password' => 'repository-secret', 'path_prefix' => 'buildpusher',
        ]);
    }

    private function runner(string &$command): Runner
    {
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('isSuccessful')->once()->andReturnTrue();
        $process->shouldReceive('getOutput')->zeroOrMoreTimes()->andReturn('Backup destination verified.');
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
