<?php

namespace Tests\Feature\Core;

use App\Core\Data\Credentials\CredentialMutationOutcome;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectMembership;
use App\Core\Models\ProjectProduct;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\WorkspaceCredentialMutations;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\Project as NativeProject;
use App\Modules\Deployer\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class DeployerCredentialMutationProviderTest extends TestCase
{
    private PlatformUser $actor;

    private Workspace $workspace;

    private Project $project;

    private User $nativeActor;

    private Organization $organization;

    private NativeProject $nativeProject;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.products.deployer.enabled' => true, 'platform.products.deployer.auth_authority' => 'core', 'billing.enforce_entitlements' => false]);
        foreach (['core', 'deployer'] as $connection) {
            config(['database.connections.'.$connection.'.database' => ':memory:']);
            DB::purge($connection);
            $this->assertSame(0, Artisan::call('platform:migrate', ['module' => $connection]));
        }
        $this->actor = PlatformUser::query()->create(['name' => 'Owner', 'email' => 'core-token@example.test', 'status' => 'active', 'email_verified_at' => now()]);
        $this->workspace = Workspace::query()->create(['owner_user_id' => $this->actor->getKey(), 'name' => 'Workspace', 'slug' => 'token-team', 'status' => 'active']);
        $member = WorkspaceMembership::query()->create(['workspace_id' => $this->workspace->getKey(), 'user_id' => $this->actor->getKey(), 'role' => 'owner', 'status' => 'active']);
        WorkspaceProductAccess::query()->create(['membership_id' => $member->getKey(), 'product' => 'deployer', 'role' => 'owner', 'status' => 'active']);
        $this->project = Project::query()->create(['workspace_id' => $this->workspace->getKey(), 'created_by_user_id' => $this->actor->getKey(), 'name' => 'App', 'slug' => 'token-app', 'status' => 'active']);
        ProjectMembership::query()->create(['project_id' => $this->project->getKey(), 'user_id' => $this->actor->getKey(), 'role' => 'owner', 'status' => 'active']);
        ProjectProduct::query()->create(['project_id' => $this->project->getKey(), 'product' => 'deployer', 'status' => 'active']);
        $this->nativeActor = User::query()->create(['name' => 'Native owner', 'email' => 'native-token@example.test', 'password' => 'hashed', 'auth_type' => 'platform', 'email_verified_at' => now()]);
        $this->organization = Organization::query()->create(['owner_id' => $this->nativeActor->getKey(), 'name' => 'Native workspace', 'slug' => 'native-token-team']);
        $this->organization->members()->attach($this->nativeActor, ['role' => 'owner']);
        $other = Organization::query()->create(['owner_id' => $this->nativeActor->getKey(), 'name' => 'Other workspace', 'slug' => 'other-token-team']);
        $this->nativeActor->forceFill(['current_organization_id' => $other->getKey()])->save();
        $this->nativeProject = NativeProject::query()->create(['organization_id' => $this->organization->getKey(), 'created_by' => $this->nativeActor->getKey(), 'name' => 'Native app', 'slug' => 'native-token-app']);
        foreach ([['user', $this->nativeActor->getKey(), 'user', $this->actor->getKey()], ['organization', $this->organization->getKey(), 'workspace', $this->workspace->getKey()]] as [$source, $id, $canonical, $canonicalId]) {
            LegacyIdentityMap::query()->create(['source_product' => 'deployer', 'source_entity' => $source, 'source_id' => (string) $id,
                'canonical_entity' => $canonical, 'canonical_id' => $canonicalId, 'status' => 'reconciled']);
        }
        ProjectResource::query()->create(['project_id' => $this->project->getKey(), 'product' => 'deployer', 'resource_type' => 'project', 'resource_id' => (string) $this->nativeProject->getKey(), 'status' => 'active']);
    }

    public function test_ulid_project_scope_uses_the_explicit_workspace_and_native_receipt_replay_issues_only_once(): void
    {
        $selectedBefore = $this->nativeActor->current_organization_id;
        $key = (string) Str::uuid();
        $first = $this->issue($key);
        $replay = $this->issue($key);
        $this->assertNotNull($first->secretForImmediateResponse());
        $this->assertNull($replay->secretForImmediateResponse());
        $tokens = $this->nativeActor->tokens()->get();
        $this->assertCount(1, $tokens);
        $this->assertContains('workspace:'.$this->organization->getKey(), $tokens->first()->abilities);
        $this->assertContains('project:'.$this->nativeProject->getKey(), $tokens->first()->abilities);
        $this->assertSame($selectedBefore, $this->nativeActor->fresh()->current_organization_id);
        $this->assertSame(1, DB::connection('deployer')->table('credential_mutation_receipts')->count());
        $this->assertStringNotContainsString($first->secretForImmediateResponse(), DB::connection('deployer')->table('credential_mutation_receipts')->get()->toJson());
    }

    public function test_rotation_requires_current_native_ownership_but_revocation_remains_available_to_the_token_owner(): void
    {
        $issued = $this->issue((string) Str::uuid());
        $successor = User::query()->create(['name' => 'New owner', 'email' => 'successor-token@example.test', 'password' => 'hashed', 'auth_type' => 'platform']);
        $this->organization->forceFill(['owner_id' => $successor->getKey()])->save();
        $this->organization->members()->updateExistingPivot($this->nativeActor->getKey(), ['role' => 'admin']);
        try {
            app(WorkspaceCredentialMutations::class)->rotate($this->actor, $this->workspace, $issued->credentialKey, (string) Str::uuid());
            $this->fail('Core must preserve the native owner-only issuance policy.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame(1, $this->nativeActor->tokens()->count());
        }
        app(WorkspaceCredentialMutations::class)->revoke($this->actor, $this->workspace, $issued->credentialKey, (string) Str::uuid());
        $this->assertSame(0, $this->nativeActor->tokens()->count());
    }

    private function issue(string $key): CredentialMutationOutcome
    {
        return app(WorkspaceCredentialMutations::class)->create($this->actor, $this->workspace, 'deployer', 'personal-access-token',
            'deployer:workspace:'.$this->organization->getKey(), 'CI', 90, ['read'], [(string) $this->project->getKey()], $key);
    }
}
