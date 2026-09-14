<?php

namespace Tests\Feature;

use App\Actions\Server\OpenServerTroubleshootingSessionAction;
use App\Models\Provider;
use App\Models\Server;
use App\Models\ServerTroubleshootingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerTroubleshootingSupervisorTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_a_bounded_uuid_systemd_unit_without_starting_it(): void
    {
        [$owner, $server] = $this->resources();
        $grant = app(OpenServerTroubleshootingSessionAction::class)->handle($server, $owner);

        $this->artisan('buildpusher:troubleshooting:supervise', [
            '--limit' => 1,
            '--dry-run' => true,
        ])
            ->expectsOutput('Would start lessbuild-troubleshooting-broker@'.$grant->session->public_id.'.service.')
            ->expectsOutput('Started 1 troubleshooting broker(s); revoked 0 ineligible session(s).')
            ->assertExitCode(0);
    }

    public function test_supervisor_revokes_a_session_before_starting_a_broker_for_a_removed_member(): void
    {
        [$owner, $server] = $this->resources();
        $member = User::factory()->create(['current_organization_id' => $owner->current_organization_id]);
        $owner->currentOrganization->members()->attach($member, ['role' => 'operator']);
        $grant = app(OpenServerTroubleshootingSessionAction::class)->handle($server, $member);
        $owner->currentOrganization->members()->detach($member);

        $this->artisan('buildpusher:troubleshooting:supervise', ['--dry-run' => true])
            ->expectsOutput('Started 0 troubleshooting broker(s); revoked 1 ineligible session(s).')
            ->assertExitCode(0);

        $this->assertSame(ServerTroubleshootingSession::STATUS_REVOKED, $grant->session->fresh()->status);
    }

    /** @return array{0: User, 1: Server} */
    private function resources(): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'DigitalOcean',
            'description' => 'Cloud provider',
            'provider' => Provider::TYPE_DIGITALOCEAN,
            'token' => 'cloud-secret',
        ]);
        $server = $owner->servers()->create([
            'provider_id' => $provider->id,
            'name' => 'Production',
            'public_ip' => '192.0.2.10',
            'ssh_private_key' => 'private-key',
            'ssh_host_key' => '192.0.2.10 ssh-ed25519 AAAAhost-key',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);

        return [$owner, $server];
    }
}
