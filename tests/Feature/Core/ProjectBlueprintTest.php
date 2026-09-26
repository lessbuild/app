<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\ProjectBlueprintProvider;
use App\Core\Data\Blueprints\BlueprintProductPreview;
use App\Core\Data\Blueprints\BlueprintProductResult;
use App\Core\Data\Blueprints\BlueprintResource;
use App\Core\Data\Blueprints\BlueprintStepAttempt;
use App\Core\Data\Blueprints\BlueprintTarget;
use App\Core\Exceptions\Blueprints\BlueprintBlocked;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectBlueprint;
use App\Core\Models\ProjectBlueprintRun;
use App\Core\Models\ProjectBlueprintVersion;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\ProjectMembership;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\Blueprints\BlueprintAuthority;
use App\Core\Services\Blueprints\PreviewProjectBlueprint;
use App\Core\Services\Blueprints\ProcessProjectBlueprint;
use App\Core\Services\Blueprints\ProjectBlueprintProviderRegistry;
use App\Core\Services\Blueprints\RequestProjectBlueprint;
use App\Core\Services\Blueprints\RetryProjectBlueprint;
use App\Core\Services\Blueprints\SaveProjectBlueprintVersion;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Tests\TestCase;

/** Authored while implementation is in progress; run only after the approved source plan is complete. */
final class ProjectBlueprintTest extends TestCase
{
    private PlatformUser $actor;

    private Workspace $workspace;

    private Project $project;

    private WorkspaceProductAccess $grant;

    private ReceiptBlueprintProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.core.database' => ':memory:', 'platform.products.deployer.enabled' => true]);
        DB::purge('core');
        $this->assertSame(0, Artisan::call('platform:migrate', ['module' => 'core']));
        $this->actor = PlatformUser::query()->create(['name' => 'Blueprint owner', 'email' => 'blueprint@example.test', 'status' => 'active']);
        $this->workspace = Workspace::query()->create(['owner_user_id' => $this->actor->getKey(), 'name' => 'Team', 'slug' => 'blueprint-team', 'status' => 'active']);
        $member = WorkspaceMembership::query()->create(['workspace_id' => $this->workspace->getKey(), 'user_id' => $this->actor->getKey(), 'role' => 'owner', 'status' => 'active']);
        $this->grant = WorkspaceProductAccess::query()->create(['membership_id' => $member->getKey(), 'product' => 'deployer', 'role' => 'owner', 'status' => 'active']);
        $this->project = Project::query()->create(['workspace_id' => $this->workspace->getKey(), 'created_by_user_id' => $this->actor->getKey(), 'name' => 'Store', 'slug' => 'store', 'status' => 'active']);
        ProjectMembership::query()->create(['project_id' => $this->project->getKey(), 'user_id' => $this->actor->getKey(), 'role' => 'owner', 'status' => 'active']);
        $this->provider = new ReceiptBlueprintProvider;
        $registry = new ProjectBlueprintProviderRegistry;
        $registry->register('deployer', $this->provider);
        $this->app->instance(ProjectBlueprintProviderRegistry::class, $registry);
    }

    public function test_preview_does_not_create_environments_runs_or_native_resources(): void
    {
        $version = $this->version();
        $preview = app(PreviewProjectBlueprint::class)->handle($this->actor, $this->workspace, $this->project, $version, ['production' => null]);

        $this->assertTrue($preview->ready());
        $this->assertSame(['new_projects' => 1], $preview->products['deployer']->planImpact);
        $this->assertSame(0, ProjectEnvironment::query()->count());
        $this->assertSame(0, ProjectBlueprintRun::query()->count());
        $this->assertSame(0, ProjectResource::query()->count());
        $this->assertSame(0, $this->provider->writes);
    }

    public function test_project_picker_keeps_authorized_targets_reachable_beyond_its_initial_window(): void
    {
        $version = $this->version();
        for ($index = 0; $index < 201; $index++) {
            $project = Project::query()->create([
                'workspace_id' => $this->workspace->getKey(), 'created_by_user_id' => $this->actor->getKey(),
                'name' => 'Earlier '.str_pad((string) $index, 3, '0', STR_PAD_LEFT), 'slug' => 'earlier-'.$index, 'status' => 'active',
            ]);
            ProjectMembership::query()->create(['project_id' => $project->getKey(), 'user_id' => $this->actor->getKey(), 'role' => 'owner', 'status' => 'active']);
        }

        $this->actingAs($this->actor, 'platform')
            ->get(route('core.workspace.blueprints.show', [$this->workspace, $version, 'project_id' => $this->project->getKey()]))
            ->assertOk()->assertSeeText('Showing the first 200 matches.')
            ->assertViewHas('project', fn (Project $project): bool => $project->is($this->project));
        $this->get(route('core.workspace.blueprints.show', [$this->workspace, $version, 'q' => 'Store']))
            ->assertOk()->assertViewHas('projects', fn ($projects): bool => $projects->count() === 1 && $projects->first()->is($this->project));

        ProjectMembership::query()->where('project_id', $this->project->getKey())->update(['revoked_at' => now()]);
        $this->get(route('core.workspace.blueprints.show', [$this->workspace, $version, 'project_id' => $this->project->getKey()]))
            ->assertNotFound();
    }

    public function test_published_versions_are_immutable_and_reject_sensitive_or_billing_configuration(): void
    {
        foreach (['api_token', 'database_password', 'verified', 'subscription'] as $field) {
            $definition = $this->definition();
            $definition['products']['deployer'][$field] = 'must-not-be-persisted';
            try {
                $this->version($definition);
                $this->fail('Sensitive or authority-bearing configuration must be rejected.');
            } catch (ValidationException) {
                $this->assertSame(0, ProjectBlueprint::query()->count());
            }
        }
        $first = $this->version();
        $second = app(SaveProjectBlueprintVersion::class)->handle($this->actor, $this->workspace, 'Release v2', null, $this->definition(), $first->blueprint);
        $this->assertSame(1, $first->fresh()->version);
        $this->assertSame(2, $second->version);
        $this->expectException(LogicException::class);
        $first->forceFill(['definition' => []])->save();
    }

    public function test_an_existing_environment_requires_an_explicit_binding_and_is_never_duplicated(): void
    {
        $environment = ProjectEnvironment::query()->create(['project_id' => $this->project->getKey(), 'created_by_user_id' => $this->actor->getKey(), 'name' => 'Production', 'slug' => 'production', 'environment_type' => 'production', 'status' => 'active']);
        $version = $this->version();
        try {
            app(PreviewProjectBlueprint::class)->handle($this->actor, $this->workspace, $this->project, $version, ['production' => null]);
            $this->fail('Existing environment keys must not be silently rebound.');
        } catch (ValidationException) {
            $this->assertSame(1, ProjectEnvironment::query()->count());
        }
        $run = $this->accept($version, ['production' => (string) $environment->getKey()]);
        $this->assertSame((string) $environment->getKey(), $run->environment_bindings['production']['id']);
        $this->assertSame(1, ProjectEnvironment::query()->count());
    }

    public function test_confirmation_is_idempotent_even_after_canonical_environments_were_created(): void
    {
        $version = $this->version();
        $key = (string) Str::uuid();
        $preview = app(PreviewProjectBlueprint::class)->handle($this->actor, $this->workspace, $this->project, $version, ['production' => null]);
        $token = app(PreviewProjectBlueprint::class)->token($preview, (string) $version->getKey(), $key);
        $first = app(RequestProjectBlueprint::class)->handle($this->actor, $this->workspace, $this->project, $version, ['production' => null], $token, $key);
        $replay = app(RequestProjectBlueprint::class)->handle($this->actor, $this->workspace, $this->project, $version, ['production' => null], $token, $key);

        $this->assertSame($first->getKey(), $replay->getKey());
        $this->assertSame(1, ProjectBlueprintRun::query()->count());
        $this->assertSame(1, ProjectEnvironment::query()->count());
        $this->assertSame(0, $this->provider->writes);
    }

    public function test_changed_native_preview_cannot_be_confirmed_with_the_old_token(): void
    {
        $version = $this->version();
        $key = (string) Str::uuid();
        $previews = app(PreviewProjectBlueprint::class);
        $preview = $previews->handle($this->actor, $this->workspace, $this->project, $version, ['production' => null]);
        $token = $previews->token($preview, (string) $version->getKey(), $key);
        $this->provider->revision++;
        try {
            app(RequestProjectBlueprint::class)->handle($this->actor, $this->workspace, $this->project, $version, ['production' => null], $token, $key);
            $this->fail('A changed native preview requires another confirmation.');
        } catch (ValidationException) {
            $this->assertSame(0, ProjectBlueprintRun::query()->count());
            $this->assertSame(0, ProjectEnvironment::query()->count());
        }
    }

    public function test_lost_native_response_resumes_from_the_receipt_without_duplicate_resources(): void
    {
        $run = $this->accept($this->version());
        $step = $run->steps()->firstOrFail();
        $this->provider->loseNextResponse = true;
        app(ProcessProjectBlueprint::class)->handle((string) $step->getKey());

        $this->assertSame('waiting', $step->fresh()->status);
        $this->assertSame(1, $this->provider->writes);
        $this->assertSame(0, ProjectResource::query()->count());
        $this->travel(20)->seconds();
        app(ProcessProjectBlueprint::class)->handle((string) $step->getKey());

        $this->assertSame(1, $this->provider->writes);
        $this->assertSame(2, ProjectResource::query()->count());
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('completed', $step->fresh()->status);
    }

    public function test_revoked_core_access_stops_the_worker_before_any_native_mutation(): void
    {
        $run = $this->accept($this->version());
        $step = $run->steps()->firstOrFail();
        $this->grant->forceFill(['revoked_at' => now()])->save();
        app(ProcessProjectBlueprint::class)->handle((string) $step->getKey());

        $this->assertSame(0, $this->provider->writes);
        $this->assertSame('blocked', $step->fresh()->status);
        $this->assertSame('authority_changed', $step->fresh()->last_error_code);
        $this->assertSame(0, ProjectResource::query()->count());
    }

    public function test_revocation_after_native_commit_preserves_the_receipt_and_blocks_core_publication(): void
    {
        $run = $this->accept($this->version());
        $step = $run->steps()->firstOrFail();
        $this->provider->afterCommit = fn () => $this->grant->forceFill(['revoked_at' => now()])->save();
        app(ProcessProjectBlueprint::class)->handle((string) $step->getKey());
        $this->assertSame('blocked', $step->fresh()->status);
        $this->assertSame(0, ProjectResource::query()->count());
        $this->assertSame(1, $this->provider->writes);

        $this->grant->forceFill(['revoked_at' => null])->save();
        app(RetryProjectBlueprint::class)->handle($this->actor, $run->fresh());
        app(ProcessProjectBlueprint::class)->handle((string) $step->getKey());
        $this->assertSame(1, $this->provider->writes);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(2, ProjectResource::query()->count());
    }

    public function test_stale_worker_cannot_publish_with_an_expired_lease(): void
    {
        $run = $this->accept($this->version());
        $step = $run->steps()->firstOrFail();
        $this->provider->afterCommit = function (): void {
            $this->travel(11)->minutes();
        };
        app(ProcessProjectBlueprint::class)->handle((string) $step->getKey());
        $this->assertSame('waiting', $step->fresh()->status);
        $this->assertSame(0, ProjectResource::query()->count());
        $this->travel(20)->seconds();
        app(ProcessProjectBlueprint::class)->handle((string) $step->getKey());
        $this->assertSame(1, $this->provider->writes);
        $this->assertSame('completed', $run->fresh()->status);
    }

    private function definition(): array
    {
        return ['schema_version' => 1, 'environments' => [['key' => 'production', 'name' => 'Production', 'type' => 'production']], 'products' => ['deployer' => ['preset' => 'laravel']]];
    }

    private function version(?array $definition = null): ProjectBlueprintVersion
    {
        return app(SaveProjectBlueprintVersion::class)->handle($this->actor, $this->workspace, 'Laravel app', null, $definition ?? $this->definition());
    }

    private function accept(ProjectBlueprintVersion $version, array $selections = ['production' => null]): ProjectBlueprintRun
    {
        $previews = app(PreviewProjectBlueprint::class);
        $preview = $previews->handle($this->actor, $this->workspace, $this->project, $version, $selections);
        $key = (string) Str::uuid();

        return app(RequestProjectBlueprint::class)->handle($this->actor, $this->workspace, $this->project, $version, $selections, $previews->token($preview, (string) $version->getKey(), $key), $key);
    }
}

/** Simulates the source transaction committing before its response reaches Core. */
final class ReceiptBlueprintProvider implements ProjectBlueprintProvider
{
    public int $writes = 0;

    public int $revision = 1;

    public bool $loseNextResponse = false;

    public ?Closure $afterCommit = null;

    private array $receipts = [];

    public function example(): array
    {
        return ['preset' => 'laravel'];
    }

    public function normalize(array $configuration): array
    {
        return Validator::make(['config' => $configuration], ['config' => ['required', 'array:preset'], 'config.preset' => ['required', 'string']])->validate()['config'];
    }

    public function preview(BlueprintTarget $target, array $configuration): BlueprintProductPreview
    {
        return new BlueprintProductPreview(['Create a local application and environment.'], ['Connect a repository and deploy explicitly.'], [], ['new_projects' => 1], ['revision' => $this->revision]);
    }

    public function apply(BlueprintStepAttempt $attempt): BlueprintProductResult
    {
        Assert::assertSame(0, DB::connection('core')->transactionLevel(), 'Native provisioning must run outside Core transactions.');
        app(BlueprintAuthority::class)->assertAttempt($attempt);
        if (isset($this->receipts[$attempt->stepId])) {
            return $this->receipts[$attempt->stepId];
        }
        if ($attempt->nativeAuthority['revision'] !== $this->revision) {
            throw new BlueprintBlocked('native_binding_changed');
        }
        $this->writes++;
        $resources = [new BlueprintResource('project', '101', 'Application')];
        foreach (array_keys($attempt->target->environments) as $index => $key) {
            $resources[] = new BlueprintResource('environment', (string) (201 + $index), 'Environment', $key, '101');
        }
        $this->receipts[$attempt->stepId] = new BlueprintProductResult($resources, ['Connect a repository.']);
        ($this->afterCommit ?? static fn () => null)();
        if ($this->loseNextResponse) {
            $this->loseNextResponse = false;
            throw new RuntimeException('Simulated loss of response after the native receipt committed.');
        }

        return $this->receipts[$attempt->stepId];
    }
}
