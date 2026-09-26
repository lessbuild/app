<?php

namespace Tests\Feature\Core;

use App\Core\Data\Credentials\CredentialMutationCommand;
use App\Core\Data\Credentials\CredentialMutationOutcome;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectMembership;
use App\Core\Models\ProjectProduct;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\WorkspaceCredentialMutationProviderRegistry;
use App\Core\Services\WorkspaceCredentialMutations;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\User as MonitorUser;
use App\Modules\Monitor\Models\Workspace as MonitorWorkspace;
use App\Modules\Monitor\Policies\EnvironmentPolicy;
use App\Modules\Monitor\Policies\MonitorPolicy;
use App\Modules\Monitor\Policies\WorkspacePolicy;
use App\Modules\Monitor\Services\Core\MonitorWorkspaceCredentialMutationProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class MonitorCredentialMutationProviderTest extends TestCase
{
    private PlatformUser $actor;

    private CoreWorkspace $workspace;

    private Project $project;

    private MonitorUser $nativeActor;

    private MonitorWorkspace $nativeWorkspace;

    private Environment $environment;

    private Monitor $queue;

    private Monitor $heartbeat;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.core.database' => ':memory:',
            'database.connections.monitor.database' => ':memory:',
            'platform.products.monitor.enabled' => true,
            'platform.products.monitor.auth_authority' => 'core',
            'billing.enforce_entitlements' => false,
        ]);
        foreach (['core', 'monitor'] as $connection) {
            DB::purge($connection);
            $this->assertSame(0, Artisan::call('platform:migrate', ['module' => $connection]));
        }

        // MonitorServiceProvider intentionally skips product adapters and policies
        // when the native module is disabled or has no host in this test process.
        Gate::policy(MonitorWorkspace::class, WorkspacePolicy::class);
        Gate::policy(Environment::class, EnvironmentPolicy::class);
        Gate::policy(Monitor::class, MonitorPolicy::class);
        app(WorkspaceCredentialMutationProviderRegistry::class)->register(
            'monitor',
            app(MonitorWorkspaceCredentialMutationProvider::class),
        );

        $this->actor = PlatformUser::query()->create([
            'name' => 'Core Owner', 'email' => 'monitor-credential@example.test', 'status' => 'active', 'email_verified_at' => now(),
        ]);
        $this->workspace = CoreWorkspace::query()->create([
            'owner_user_id' => $this->actor->getKey(), 'name' => 'Workspace', 'slug' => 'monitor-credential-team', 'status' => 'active',
        ]);
        $membership = WorkspaceMembership::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'user_id' => $this->actor->getKey(), 'role' => 'owner', 'status' => 'active',
        ]);
        WorkspaceProductAccess::query()->create([
            'membership_id' => $membership->getKey(), 'product' => 'monitor', 'role' => 'owner', 'status' => 'active',
        ]);
        $this->project = Project::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'created_by_user_id' => $this->actor->getKey(),
            'name' => 'App', 'slug' => 'monitor-credential-app', 'status' => 'active',
        ]);
        ProjectMembership::query()->create([
            'project_id' => $this->project->getKey(), 'user_id' => $this->actor->getKey(), 'role' => 'owner', 'status' => 'active',
        ]);
        ProjectProduct::query()->create(['project_id' => $this->project->getKey(), 'product' => 'monitor', 'status' => 'active']);

        $this->nativeActor = MonitorUser::query()->create([
            'name' => 'Native Owner', 'email' => 'native-monitor@example.test', 'password' => 'hashed',
            'email_verified_at' => now(),
        ]);
        $this->nativeActor->forceFill(['platform_user_id' => $this->actor->getKey()])->save();
        $this->nativeWorkspace = MonitorWorkspace::query()->forceCreate([
            'owner_id' => $this->nativeActor->getKey(), 'name' => 'Native Workspace', 'slug' => 'native-monitor-workspace', 'plan' => 'free',
        ]);
        $this->nativeWorkspace->members()->attach($this->nativeActor, ['role' => 'owner']);
        $application = Application::query()->forceCreate([
            'workspace_id' => $this->nativeWorkspace->getKey(), 'name' => 'Native App', 'slug' => 'native-monitor-app', 'framework' => 'laravel',
        ]);
        $this->environment = Environment::query()->forceCreate([
            'application_id' => $application->getKey(), 'name' => 'Production', 'slug' => 'production',
            'status' => 'active',
        ]);
        $this->queue = Monitor::query()->forceCreate([
            'environment_id' => $this->environment->getKey(), 'name' => 'Queue worker', 'type' => 'queue',
            'request_url' => 'https://example.test/queue',
        ]);
        $this->heartbeat = Monitor::query()->forceCreate([
            'environment_id' => $this->environment->getKey(), 'name' => 'Heartbeat', 'type' => 'heartbeat',
            'request_url' => 'https://example.test/heartbeat',
        ]);

        $this->map('user', (string) $this->nativeActor->getKey(), 'user', (string) $this->actor->getKey());
        $this->map('workspace', (string) $this->nativeWorkspace->getKey(), 'workspace', (string) $this->workspace->getKey());
        ProjectResource::query()->create([
            'project_id' => $this->project->getKey(), 'product' => 'monitor', 'resource_type' => 'application',
            'resource_id' => (string) $application->getKey(), 'status' => 'active',
        ]);
        ProjectResource::query()->create([
            'project_id' => $this->project->getKey(), 'product' => 'monitor', 'resource_type' => 'environment',
            'resource_id' => (string) $this->environment->getKey(), 'status' => 'active', 'name' => 'Production',
        ]);
    }

    public function test_ingest_token_issue_rotate_revoke_and_receipt_replay_never_persist_or_reissue_secret(): void
    {
        $issueKey = (string) Str::uuid();
        $first = $this->createIngest($issueKey);
        $secret = $first->secretForImmediateResponse();
        $this->assertNotNull($secret);
        $this->assertSame(hash('sha256', $secret), DB::connection('monitor')->table('ingest_tokens')->value('token_hash'));

        $rotated = app(WorkspaceCredentialMutations::class)->rotate($this->actor, $this->workspace, $first->credentialKey, (string) Str::uuid());
        $rotatedSecret = $rotated->secretForImmediateResponse();
        $this->assertNotNull($rotatedSecret);
        $this->assertSame(2, DB::connection('monitor')->table('ingest_tokens')->count());

        $revoked = app(WorkspaceCredentialMutations::class)->revoke($this->actor, $this->workspace, $rotated->credentialKey, (string) Str::uuid());
        $this->assertSame('revoked', $revoked->status);
        $this->assertNotNull(DB::connection('monitor')->table('ingest_tokens')->whereNotNull('revoked_at')->first());

        $replay = $this->createIngest($issueKey);
        $this->assertNull($replay->secretForImmediateResponse());
        $this->assertSame(2, DB::connection('monitor')->table('ingest_tokens')->count());
        $this->assertSecretAbsentFromReceipts([(string) $secret, $rotatedSecret]);
    }

    public function test_core_monitor_credential_response_shows_the_secret_once_without_persisting_it(): void
    {
        $payload = [
            'idempotency_key' => (string) Str::uuid(),
            'product' => 'monitor',
            'credential_type' => 'ingest-token',
            'target_key' => 'monitor:environment:'.$this->environment->getKey(),
            'name' => 'Core ingestion token',
            'expires_in_days' => 60,
        ];
        $response = $this->actingAs($this->actor, 'platform')->post(route('core.workspace.credentials.store', $this->workspace), $payload);
        $response->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertSee('Copy this secret now.');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        preg_match('/bcn_[A-Za-z0-9]{64}/', $response->getContent(), $matches);
        $secret = $matches[0] ?? null;
        $this->assertIsString($secret);
        $this->assertSame(hash('sha256', $secret), DB::connection('monitor')->table('ingest_tokens')->value('token_hash'));
        $this->assertStringNotContainsString($secret, json_encode(session()->all(), JSON_THROW_ON_ERROR));
        $this->assertSecretAbsentFromReceipts([$secret]);

        $this->post(route('core.workspace.credentials.store', $this->workspace), $payload)
            ->assertOk()->assertDontSee($secret)->assertSee('one-time secret is no longer available');
        $this->assertSame(1, DB::connection('monitor')->table('ingest_tokens')->count());
    }

    public function test_current_core_grant_and_environment_project_mapping_are_required_even_for_replay(): void
    {
        $first = $this->createIngest((string) Str::uuid());
        WorkspaceProductAccess::query()->where('membership_id', $this->workspace->memberships()->where('user_id', $this->actor->getKey())->value('id'))
            ->where('product', 'monitor')->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->expectHttpStatus(404, fn () => $this->createIngest($this->operationFor($first->credentialKey)));

        // Restore the Core grant, then revoke the exact resource/project link.
        WorkspaceProductAccess::query()->where('membership_id', $this->workspace->memberships()->where('user_id', $this->actor->getKey())->value('id'))
            ->where('product', 'monitor')->update(['status' => 'active', 'revoked_at' => null]);
        ProjectResource::query()->where('product', 'monitor')->where('resource_type', 'environment')
            ->where('resource_id', (string) $this->environment->getKey())->update(['status' => 'inactive']);
        $this->expectHttpStatus(404, fn () => app(WorkspaceCredentialMutations::class)->rotate(
            $this->actor, $this->workspace, $first->credentialKey, (string) Str::uuid(),
        ));
    }

    public function test_native_manager_role_is_rechecked_before_ingest_mutation(): void
    {
        $this->nativeWorkspace->members()->updateExistingPivot($this->nativeActor->getKey(), ['role' => 'member']);

        $this->expectHttpStatus(403, fn () => $this->createIngest((string) Str::uuid()));
        $this->assertSame(0, DB::connection('monitor')->table('ingest_tokens')->count());
    }

    public function test_a_native_target_in_another_workspace_is_rejected(): void
    {
        $otherWorkspace = MonitorWorkspace::query()->forceCreate([
            'owner_id' => $this->nativeActor->getKey(), 'name' => 'Other Native Workspace', 'slug' => 'other-native-monitor-workspace', 'plan' => 'free',
        ]);
        $otherWorkspace->members()->attach($this->nativeActor, ['role' => 'owner']);
        $application = Application::query()->forceCreate([
            'workspace_id' => $otherWorkspace->getKey(), 'name' => 'Other App', 'slug' => 'other-native-monitor-app', 'framework' => 'laravel',
        ]);
        $otherEnvironment = Environment::query()->forceCreate([
            'application_id' => $application->getKey(), 'name' => 'Production', 'slug' => 'production', 'status' => 'active',
        ]);

        $this->expectHttpStatus(404, fn () => app(WorkspaceCredentialMutations::class)->create(
            $this->actor, $this->workspace, 'monitor', 'ingest-token', 'monitor:environment:'.$otherEnvironment->getKey(),
            'Wrong workspace', null, [], [], (string) Str::uuid(),
        ));
        $this->assertSame(0, DB::connection('monitor')->table('ingest_tokens')->count());
    }

    public function test_queue_and_heartbeat_keys_reject_type_confusion_and_require_native_verification(): void
    {
        $this->expectHttpStatus(404, fn () => app(WorkspaceCredentialMutations::class)->create(
            $this->actor, $this->workspace, 'monitor', 'heartbeat-key', 'monitor:monitor:'.$this->queue->getKey(),
            null, null, [], [], (string) Str::uuid(),
        ));
        $this->expectHttpStatus(404, fn () => app(WorkspaceCredentialMutations::class)->create(
            $this->actor, $this->workspace, 'monitor', 'queue-key', 'monitor:monitor:'.$this->heartbeat->getKey(),
            null, null, [], [], (string) Str::uuid(),
        ));

        $this->nativeActor->forceFill(['email_verified_at' => null])->save();
        $this->expectHttpStatus(403, fn () => app(WorkspaceCredentialMutations::class)->create(
            $this->actor, $this->workspace, 'monitor', 'queue-key', 'monitor:monitor:'.$this->queue->getKey(),
            null, null, [], [], (string) Str::uuid(),
        ));
        $this->assertNull($this->queue->fresh()->queue_token_hash);

        $this->nativeActor->forceFill(['email_verified_at' => now()])->save();
        $queueKey = app(WorkspaceCredentialMutations::class)->create(
            $this->actor, $this->workspace, 'monitor', 'queue-key', 'monitor:monitor:'.$this->queue->getKey(),
            null, null, [], [], (string) Str::uuid(),
        );
        $heartbeatKey = app(WorkspaceCredentialMutations::class)->create(
            $this->actor, $this->workspace, 'monitor', 'heartbeat-key', 'monitor:monitor:'.$this->heartbeat->getKey(),
            null, null, [], [], (string) Str::uuid(),
        );
        $this->assertStringStartsWith('bqk_', (string) $queueKey->secretForImmediateResponse());
        $this->assertStringStartsWith('bch_', (string) $heartbeatKey->secretForImmediateResponse());
        $this->assertSame(hash('sha256', (string) $queueKey->secretForImmediateResponse()), $this->queue->fresh()->queue_token_hash);
        $this->assertSame(hash('sha256', (string) $heartbeatKey->secretForImmediateResponse()), $this->heartbeat->fresh()->heartbeat_token_hash);
        $this->assertSecretAbsentFromReceipts([(string) $queueKey->secretForImmediateResponse(), (string) $heartbeatKey->secretForImmediateResponse()]);
    }

    public function test_ingest_issue_does_not_require_verified_email_but_queue_key_does(): void
    {
        $this->nativeActor->forceFill(['email_verified_at' => null])->save();
        $issued = $this->createIngest((string) Str::uuid());
        $this->assertNotNull($issued->secretForImmediateResponse());
        $this->expectHttpStatus(403, fn () => app(WorkspaceCredentialMutations::class)->create(
            $this->actor, $this->workspace, 'monitor', 'queue-key', 'monitor:monitor:'.$this->queue->getKey(),
            null, null, [], [], (string) Str::uuid(),
        ));
    }

    public function test_missing_native_receipt_fails_closed_on_core_replay_only(): void
    {
        $provider = app(MonitorWorkspaceCredentialMutationProvider::class);
        $command = new CredentialMutationCommand(
            'create', 'monitor', 'ingest-token', null, 'monitor:environment:'.$this->environment->getKey(),
            'Lost operation', null, [], [], (string) Str::uuid(), hash('sha256', 'lost'), true,
        );

        $this->expectHttpStatus(409, fn () => $provider->mutate($this->actor, $this->workspace, $command));
        $this->assertSame(0, DB::connection('monitor')->table('ingest_tokens')->count());
        $this->assertSame(0, DB::connection('monitor')->table('credential_mutation_receipts')->count());
    }

    private function createIngest(string $idempotencyKey): CredentialMutationOutcome
    {
        return app(WorkspaceCredentialMutations::class)->create(
            $this->actor, $this->workspace, 'monitor', 'ingest-token', 'monitor:environment:'.$this->environment->getKey(),
            'Core integration', 60, [], [], $idempotencyKey,
        );
    }

    private function map(string $sourceEntity, string $sourceId, string $canonicalEntity, string $canonicalId): void
    {
        LegacyIdentityMap::query()->create([
            'source_product' => 'monitor', 'source_entity' => $sourceEntity, 'source_id' => $sourceId,
            'canonical_entity' => $canonicalEntity, 'canonical_id' => $canonicalId, 'status' => 'reconciled',
        ]);
    }

    private function expectHttpStatus(int $status, callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected an HTTP authorization or validation failure.');
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        } catch (AuthorizationException $exception) {
            $this->assertSame($status, $exception->status());
        } catch (QueryException $exception) {
            $this->fail('Unexpected database failure: '.$exception->getMessage());
        }
    }

    /** @param list<string> $secrets */
    private function assertSecretAbsentFromReceipts(array $secrets): void
    {
        foreach ([DB::connection('core')->table('workspace_credential_mutations'), DB::connection('monitor')->table('credential_mutation_receipts')] as $receipts) {
            $serialized = $receipts->get()->toJson();
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $serialized);
            }
        }
    }
}
