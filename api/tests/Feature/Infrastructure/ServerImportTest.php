<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Enums\AuditAction;
use App\Models\AuditEntry;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerImportAssessment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use phpseclib4\Crypt\EC;
use RuntimeException;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class ServerImportTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private string $base;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $this->fakeDiscovery();
        $this->project = Project::factory()->withServices(['infrastructure'])->create();
        $this->owner = $this->ownerOf($this->project);
        $this->onTier($this->project, 'deploy', 'pro');
        $this->base = "/api/app/projects/{$this->project->id}/infrastructure";
        $this->key = EC::createKey('Ed25519')->toString('OpenSSH');
    }

    /**
     * An inspected server is imported once and provisioned over ssh.
     */
    public function test_an_inspected_server_is_imported_once_and_provisioned_over_ssh(): void
    {
        $this->actingAs($this->owner)->getJson("{$this->base}/imports/create")->assertOk()->assertJsonPath('types.0.value', 'app');
        $response = $this->actingAs($this->owner)->postJson("{$this->base}/imports", $this->import());
        $assessment = ServerImportAssessment::query()->sole();
        $response->assertJsonRedirect("{$this->base}/imports/{$assessment->id}");
        $this->assertStringNotContainsString('PRIVATE KEY', (string) $assessment->getRawOriginal('configuration'));

        $this->getJson("{$this->base}/imports/{$assessment->id}")->assertOk()->assertJsonPath('assessment.fingerprint', 'SHA256:imported')->assertSee('nginx')->assertSee('Existing services may be reconfigured')->assertJsonPath('usable', true);
        $this->postJson("{$this->base}/imports/{$assessment->id}/confirm")->assertSuccessful()->assertJsonStructure(['secrets' => ['root']]);

        $server = Server::query()->sole();
        $this->assertSame('legacy-web', $server->name);
        $this->assertSame('198.51.100.20', $server->public_ip);
        $this->assertFalse($server->ssh_key_owned);
        $this->assertStringStartsWith('ssh-ed25519 ', (string) $server->ssh_public_key);
        $this->assertSame('SHA256:imported', $server->ssh_host_fingerprint);
        $this->assertSame(Server::STATUS_PROVISIONING, $server->provisioning_status);
        $this->assertNull($server->password);
        $this->assertSame(4242, $server->provisioning_process_id);
        $this->assertStringContainsString('provisionPing '.$server->id.' 1', $this->scripts->started[0]['script']);
        $this->assertNotNull($assessment->fresh()?->consumed_at);
        $this->assertSame(AuditAction::ServerImported, AuditEntry::query()->where('account_id', $this->project->account_id)->sole()->action);

        $this->post("{$this->base}/imports/{$assessment->id}/confirm")->assertJsonValidationErrors('confirmation');
        $this->assertDatabaseCount('servers', 1);
    }

    /**
     * Instead of pasting a private key, people can add a key made for the import to root's authorized_keys; its
     * private half is used once, from their session.
     */
    public function test_a_server_can_be_imported_with_a_key_made_for_it(): void
    {
        $publicKey = (string) $this->actingAs($this->owner)->getJson("{$this->base}/imports/create")->assertOk()->json('publicKey');
        $this->assertStringStartsWith('ssh-ed25519 ', $publicKey);
        $this->assertSame($publicKey, $this->getJson("{$this->base}/imports/create")->json('publicKey'));

        $data = $this->import();
        unset($data['ssh_private_key']);
        $this->postJson("{$this->base}/imports", [...$data, 'key_source' => 'ours'])->assertSuccessful();
        $assessment = ServerImportAssessment::query()->sole();
        $this->postJson("{$this->base}/imports/{$assessment->id}/confirm")->assertSuccessful();
        $this->assertSame(explode(' ', $publicKey)[1], explode(' ', (string) Server::query()->sole()->ssh_public_key)[1]);

        $this->postJson("{$this->base}/imports", [...$data, 'key_source' => 'ours'])->assertJsonValidationErrors('key_source');
    }

    /**
     * Bad keys failed inspections and expired reviews are refused.
     */
    public function test_bad_keys_failed_inspections_and_expired_reviews_are_refused(): void
    {
        $this->actingAs($this->owner)->postJson("{$this->base}/imports", $this->import(['ssh_private_key' => 'not a key']))->assertJsonValidationErrors(['ssh_private_key' => 'Paste an unencrypted SSH private key.']);
        $this->actingAs($this->owner)->postJson("{$this->base}/imports", $this->import(['public_ip' => 'example.com']))->assertJsonValidationErrors('public_ip');

        $this->app->instance(\App\Services\Infrastructure\ServerDiscovery::class, new class extends \App\Services\Infrastructure\ServerDiscovery
        {
            public function __construct() {}

            public function inspect(array $configuration): array
            {
                throw new RuntimeException('Only Ubuntu servers are supported for safe import.');
            }
        });
        $this->actingAs($this->owner)->postJson("{$this->base}/imports", $this->import())->assertJsonValidationErrors(['connection' => 'Only Ubuntu servers are supported for safe import.']);
        $this->assertDatabaseCount('server_import_assessments', 0);

        $this->fakeDiscovery();
        $this->actingAs($this->owner)->postJson("{$this->base}/imports", $this->import());
        $assessment = ServerImportAssessment::query()->sole();
        $this->travel(31)->minutes();
        $this->getJson("{$this->base}/imports/{$assessment->id}")->assertOk()->assertJsonPath('usable', false);
        $this->post("{$this->base}/imports/{$assessment->id}/confirm")->assertJsonValidationErrors('confirmation');

        $other = User::factory()->create();
        $this->addMember($this->project, $other, \App\Enums\AccountRole::Admin);
        $this->actingAs($other)->getJson("{$this->base}/imports/{$assessment->id}")->assertNotFound();
        $this->assertDatabaseCount('servers', 0);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string|int>
     */
    private function import(array $overrides = []): array
    {
        return ['name' => 'legacy web', 'type' => 'app', 'public_ip' => '198.51.100.20', 'ssh_port' => 22, 'ssh_private_key' => $this->key, ...$overrides];
    }
}
