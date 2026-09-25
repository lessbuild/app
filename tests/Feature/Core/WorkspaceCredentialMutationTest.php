<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\WorkspaceCredentialMutationProvider;
use App\Core\Data\Credentials\CredentialCreateOption;
use App\Core\Data\Credentials\CredentialCreateOptions;
use App\Core\Data\Credentials\CredentialMutationCommand;
use App\Core\Data\Credentials\CredentialMutationOutcome;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\WorkspaceCredentialMutationProviderRegistry;
use App\Core\Services\WorkspaceCredentialMutations;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class WorkspaceCredentialMutationTest extends TestCase
{
    private PlatformUser $actor;

    private Workspace $workspace;

    private WorkspaceMembership $membership;

    private FakeCredentialMutationProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.core.database' => ':memory:', 'platform.products.deployer.enabled' => true]);
        DB::purge('core');
        Artisan::call('platform:migrate', ['module' => 'core']);

        $this->actor = PlatformUser::query()->create(['name' => 'Owner', 'email' => 'credential-owner@example.test', 'status' => 'active']);
        $this->workspace = Workspace::query()->create(['owner_user_id' => $this->actor->getKey(), 'name' => 'Team', 'slug' => 'credential-team', 'status' => 'active']);
        $this->membership = WorkspaceMembership::query()->create(['workspace_id' => $this->workspace->getKey(), 'user_id' => $this->actor->getKey(), 'role' => 'owner', 'status' => 'active']);
        WorkspaceProductAccess::query()->create(['membership_id' => $this->membership->getKey(), 'product' => 'deployer', 'role' => 'owner', 'status' => 'active']);
        $this->provider = new FakeCredentialMutationProvider;
        $registry = new WorkspaceCredentialMutationProviderRegistry;
        $registry->register('deployer', $this->provider);
        $this->app->instance(WorkspaceCredentialMutationProviderRegistry::class, $registry);
    }

    public function test_idempotent_replay_never_returns_or_mints_a_second_secret(): void
    {
        $mutations = app(WorkspaceCredentialMutations::class);
        $first = $mutations->create($this->actor, $this->workspace, 'deployer', 'personal-access-token', 'deployer:workspace:7', 'CI', 90, ['read'], [], 'c7cd1733-4696-4df2-a7ee-5a0c8ecc0b4e');
        $replay = $mutations->create($this->actor, $this->workspace, 'deployer', 'personal-access-token', 'deployer:workspace:7', 'CI', 90, ['read'], [], 'c7cd1733-4696-4df2-a7ee-5a0c8ecc0b4e');

        $this->assertSame('one-time-secret', $first->secretForImmediateResponse());
        $this->assertSame('secret_unavailable', $replay->status);
        $this->assertNull($replay->secretForImmediateResponse());
        $this->assertSame(1, $this->provider->mutationCount);
        $this->assertDatabaseHas('workspace_credential_mutations', ['status' => 'issued', 'credential_key' => 'deployer:personal-access-token:1'], 'core');
    }

    public function test_replay_rechecks_current_membership_and_product_grant(): void
    {
        $mutations = app(WorkspaceCredentialMutations::class);
        $key = 'c7cd1733-4696-4df2-a7ee-5a0c8ecc0b4e';
        $mutations->create($this->actor, $this->workspace, 'deployer', 'personal-access-token', 'deployer:workspace:7', 'CI', 90, ['read'], [], $key);
        $this->membership->forceFill(['status' => 'revoked'])->save();

        try {
            $mutations->create($this->actor, $this->workspace, 'deployer', 'personal-access-token', 'deployer:workspace:7', 'CI', 90, ['read'], [], $key);
            $this->fail('Revoked Core membership must conceal prior receipt data.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertSame(1, $this->provider->mutationCount);
    }

    public function test_completed_core_receipt_still_requires_current_native_authority(): void
    {
        $service = app(WorkspaceCredentialMutations::class);
        $key = 'c7cd1733-4696-4df2-a7ee-5a0c8ecc0b4e';
        $service->create($this->actor, $this->workspace, 'deployer', 'personal-access-token', 'deployer:workspace:7', 'CI', 90, ['read'], [], $key);
        $this->provider->nativeAccess = false;
        try {
            $service->create($this->actor, $this->workspace, 'deployer', 'personal-access-token', 'deployer:workspace:7', 'CI', 90, ['read'], [], $key);
            $this->fail('A Core receipt must not bypass current native access.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
            $this->assertSame(1, $this->provider->mutationCount);
        }
    }

    public function test_creation_page_and_private_post_response_use_real_core_views_without_flashing_secrets(): void
    {
        $this->actingAs($this->actor, 'platform')->get(route('core.workspace.credentials.create', $this->workspace))
            ->assertOk()->assertSee('Create credential');
        $this->provider->secret = '<one-time & secret>';
        $payload = ['idempotency_key' => 'c7cd1733-4696-4df2-a7ee-5a0c8ecc0b4e', 'product' => 'deployer',
            'credential_type' => 'personal-access-token', 'target_key' => 'deployer:workspace:7',
            'name' => 'CI', 'expires_in_days' => 90, 'permissions' => ['read']];
        $response = $this->post(route('core.workspace.credentials.store', $this->workspace), $payload);
        $response->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('&lt;one-time &amp; secret&gt;', false)->assertDontSee('<one-time & secret>', false);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString($this->provider->secret, json_encode(session()->all(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($this->provider->secret, DB::connection('core')->table('workspace_credential_mutations')->get()->toJson());
        $this->post(route('core.workspace.credentials.store', $this->workspace), $payload)
            ->assertOk()->assertDontSee('&lt;one-time &amp; secret&gt;', false)->assertSee('one-time secret is no longer available');
        $this->assertSame(1, $this->provider->mutationCount);
    }
}

final class FakeCredentialMutationProvider implements WorkspaceCredentialMutationProvider
{
    public int $mutationCount = 0;

    public bool $nativeAccess = true;

    public string $secret = 'one-time-secret';

    /** @var array<string, true> */
    private array $receipts = [];

    public function createOptions(PlatformUser $actor, Workspace $workspace, Collection $projects): CredentialCreateOptions
    {
        return new CredentialCreateOptions(collect([new CredentialCreateOption('deployer', 'personal-access-token', 'API token', 'deployer:workspace:7', 'Team')]));
    }

    public function mutate(PlatformUser $actor, Workspace $workspace, CredentialMutationCommand $command): CredentialMutationOutcome
    {
        abort_unless($this->nativeAccess, 404);
        if ($command->replayOnly) {
            if (! isset($this->receipts[$command->operationId])) {
                abort(409, 'Missing native operation receipt.');
            }

            return new CredentialMutationOutcome('secret_unavailable', 'deployer:personal-access-token:1', 'personal-access-token', 'CI');
        }
        $this->mutationCount++;
        $this->receipts[$command->operationId] = true;

        return new CredentialMutationOutcome('issued', 'deployer:personal-access-token:1', 'personal-access-token', 'CI', $this->secret);
    }
}
