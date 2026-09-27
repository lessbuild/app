<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Actions\ApiTokens\CreateApiToken;
use App\Data\ApiTokens\CreateApiTokenData;
use App\Enums\AccountRole;
use App\Enums\ApiScope;
use App\Enums\ProviderType;
use App\Models\Build;
use App\Models\ConfigurationApplication;
use App\Models\ConfigurationOperation;
use App\Models\ConfigurationOwnership;
use App\Models\ConfigurationReview;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\Infrastructure\ProvisioningCallbackUrl;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class ConfigurationTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private Website $website;

    private Repository $repository;

    private EnvironmentVariable $secret;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $this->project = Project::factory()->withServices(['deploy'])->create();
        $this->owner = $this->ownerOf($this->project);
        $this->onTier($this->project, 'deploy', 'pro');
        $server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $this->project->account_id])->id]);
        $this->website = Website::factory()->create(['server_id' => $server->id, 'deployment_slug' => 'shop']);
        $this->repository = Repository::factory()->create(['project_id' => $this->project->id, 'website_id' => $this->website->id, 'provider_id' => Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $this->project->account_id])->id]);
        $production = $this->project->environments()->where('slug', 'production')->firstOrFail();
        $this->secret = new EnvironmentVariable;
        $this->secret->forceFill(['environment_id' => $production->id, 'key' => 'STRIPE_SECRET', 'value' => 'sk_test_1', 'is_secret' => true, 'scope' => 'all', 'current_version' => 1])->save();
        $this->base = "/projects/{$this->project->id}/deploy/configuration";
        $this->withoutMiddleware(RequirePassword::class);
    }

    public function test_a_document_is_planned_reviewed_and_applied_then_deploys(): void
    {
        $this->actingAs($this->owner)->post("{$this->base}/plan", ['document' => $this->document(), 'bindings' => $this->bindings()])->assertRedirect($this->base);
        $this->actingAs($this->owner)->get($this->base)->assertSee('Planned changes')->assertSee('Create')->assertSee('Deploy');
        $this->assertSame(0, Environment::query()->where('slug', 'staging')->count(), 'Planning writes nothing.');

        $this->actingAs($this->owner)->post("{$this->base}/reviews", ['document' => $this->document(), 'bindings' => $this->bindings()])->assertRedirect();
        $review = ConfigurationReview::query()->sole();
        $this->assertTrue($review->summary['apply_available']);
        $this->actingAs($this->owner)->post("{$this->base}/reviews/{$review->id}/apply")->assertRedirect();

        $staging = Environment::query()->where('slug', 'staging')->sole();
        $this->assertSame(['node', 3000, 'npm start'], [$staging->runtime_type, $staging->container_port, $staging->start_command]);
        $this->assertSame(['queue', 2], [$staging->processes()->sole()->name, $staging->processes()->sole()->replicas]);
        $variable = $staging->variables()->sole();
        $this->assertSame(['STRIPE_SECRET', 'sk_test_1', true, 'runtime'], [$variable->key, $variable->value, $variable->is_secret, $variable->scope]);
        $this->assertSame('shop', $staging->resources()->sole()->configuration['variables']['DB_DATABASE'] ?? null, 'Managed MySQL uses the placement website’s database.');
        $this->assertSame(4, ConfigurationOwnership::query()->count());
        $this->assertSame($staging->id, $this->repository->refresh()->environment_id);

        $operation = ConfigurationOperation::query()->sole();
        $build = Build::query()->sole();
        $this->assertSame(['delivered', $build->id, 'api', Build::STATUS_RUNNING], [$operation->status, $operation->build_id, $build->trigger_source, $build->status]);
        $application = ConfigurationApplication::query()->sole();
        $this->actingAs($this->owner)->get("{$this->base}/applications/{$application->id}")->assertOk()->assertSee('Deploying')->assertSee("#{$build->id}");

        // Applying again returns the same receipt; the build finishing completes the configuration.
        $this->actingAs($this->owner)->post("{$this->base}/reviews/{$review->id}/apply")->assertRedirect("{$this->base}/applications/{$application->id}");
        $this->post(ProvisioningCallbackUrl::buildStatus($build), ['status' => 15]);
        $this->command('configuration:dispatch')->assertSuccessful();
        $this->assertSame(['succeeded', 'succeeded'], [$operation->refresh()->status, $application->refresh()->status]);

        // The same intent again refers to that deploy instead of deploying twice.
        $this->actingAs($this->owner)->post("{$this->base}/reviews", ['document' => $this->document(), 'bindings' => $this->bindings()]);
        $again = ConfigurationReview::query()->latest('id')->firstOrFail();
        $this->assertSame('update', collect($again->summary['changes'])->firstWhere('kind', 'environment')['action'] ?? null);
        $this->actingAs($this->owner)->post("{$this->base}/reviews/{$again->id}/apply");
        $this->assertSame(1, Build::query()->count());
        $this->assertSame('succeeded', ConfigurationApplication::query()->latest('id')->firstOrFail()->status);
    }

    public function test_existing_objects_need_adoption_and_reviews_go_stale(): void
    {
        $staging = Environment::factory()->create(['project_id' => $this->project->id, 'slug' => 'staging', 'name' => 'Staging', 'kind' => 'staging']);
        $this->actingAs($this->owner)->post("{$this->base}/reviews", ['document' => $this->document(deploy: false), 'bindings' => $this->bindings()]);
        $review = ConfigurationReview::query()->sole();
        $this->assertFalse($review->summary['apply_available']);
        $this->actingAs($this->owner)->post("{$this->base}/reviews/{$review->id}/apply")->assertSessionHasErrors('review');

        $this->actingAs($this->owner)->post("{$this->base}/reviews", ['document' => $this->document(deploy: false, adopt: true), 'bindings' => $this->bindings()]);
        $adopting = ConfigurationReview::query()->latest('id')->firstOrFail();
        $this->assertSame('adopt', collect($adopting->summary['changes'])->firstWhere('kind', 'environment')['action'] ?? null);

        // A change after the review makes it stale; only its requester may apply it; it expires.
        $staging->forceFill(['build_command' => 'make'])->save();
        $this->actingAs($this->owner)->post("{$this->base}/reviews/{$adopting->id}/apply")->assertSessionHasErrors(['review' => 'The configuration changed after this review. Create a new review.']);
        $this->actingAs($this->owner)->post("{$this->base}/reviews", ['document' => $this->document(deploy: false, adopt: true), 'bindings' => $this->bindings()]);
        $fresh = ConfigurationReview::query()->latest('id')->firstOrFail();
        $member = User::factory()->create();
        $this->addMember($this->project, $member, AccountRole::Member);
        $this->actingAs($member)->post("{$this->base}/reviews/{$fresh->id}/apply")->assertForbidden();
        $this->travel(16)->minutes();
        $this->actingAs($this->owner)->post("{$this->base}/reviews/{$fresh->id}/apply")->assertSessionHasErrors('review');

        $viewer = User::factory()->create();
        $this->addMember($this->project, $viewer, AccountRole::Viewer);
        $this->actingAs($viewer)->post("{$this->base}/plan", ['document' => $this->document(), 'bindings' => $this->bindings()])->assertForbidden();
    }

    public function test_documents_are_validated_and_production_stays(): void
    {
        $this->actingAs($this->owner)->post("{$this->base}/plan", ['document' => "version: 1\nenvironments: {}", 'bindings' => '{}'])->assertSessionHasErrors('document');
        $this->actingAs($this->owner)->post("{$this->base}/plan", ['document' => "version: 2\nanchor: &a [1]\nenvironments: *a", 'bindings' => '{}'])->assertSessionHasErrors('document');
        $this->actingAs($this->owner)->post("{$this->base}/plan", ['document' => $this->document(), 'bindings' => '{"placements": {"web": 999999}}'])->assertSessionHasErrors('bindings');
        $this->actingAs($this->owner)->post("{$this->base}/plan", ['document' => "version: 2\nremove:\n  environments: [production]", 'bindings' => '{}'])->assertSessionHasErrors('plan');
        $this->actingAs($this->owner)->post("{$this->base}/plan", ['document' => str_replace('type: staging', 'type: production', $this->document()), 'bindings' => $this->bindings()])->assertSessionHasErrors('plan');
    }

    public function test_blocked_deploys_wait_for_their_gates_and_failed_ones_retry_over_the_api(): void
    {
        $token = app(CreateApiToken::class)->handle($this->owner, $this->project->account, new CreateApiTokenData('ci', [ApiScope::DeployWrite], 30))->plainText;
        $this->api($token, 'POST', "/api/v1/projects/{$this->project->id}/configuration/plan", ['document' => $this->document(), 'bindings' => json_decode($this->bindings(), true)])
            ->assertOk()->assertJsonPath('data.apply_available', true)->assertJsonPath('data.version', 2);
        $review = $this->api($token, 'POST', "/api/v1/projects/{$this->project->id}/configuration/reviews", ['document' => $this->document(), 'bindings' => json_decode($this->bindings(), true)])
            ->assertCreated()->json('data.id');

        // Something else is deploying to the website, so the configuration's deploy waits.
        $running = Build::factory()->create(['repository_id' => Repository::factory()->create(['project_id' => $this->project->id, 'website_id' => $this->website->id])->id, 'status' => Build::STATUS_RUNNING]);
        $application = $this->api($token, 'POST', "/api/v1/projects/{$this->project->id}/configuration/reviews/{$review}/apply")->assertStatus(422)->json();
        $running->forceFill(['status' => Build::STATUS_SUCCEEDED])->save();
        $application = $this->api($token, 'POST', "/api/v1/projects/{$this->project->id}/configuration/reviews/{$review}/apply")->assertOk()->assertJsonPath('data.operations.0.status', 'delivered')->json('data.id');

        $build = Build::query()->where('trigger_source', 'api')->sole();
        $this->post(ProvisioningCallbackUrl::buildFailure($build), ['message' => 'npm ci failed', 'exit_code' => 1]);
        $operation = ConfigurationOperation::query()->sole();
        $this->api($token, 'GET', "/api/v1/projects/{$this->project->id}/configuration/applications/{$application}")->assertOk()->assertJsonPath('data.status', 'remote_failed');
        $retried = $this->api($token, 'POST', "/api/v1/projects/{$this->project->id}/configuration/applications/{$application}/operations/{$operation->id}/retry")
            ->assertOk()->json('retry_operation_id');
        $retry = ConfigurationOperation::query()->findOrFail((int) $retried);
        $this->assertSame(['delivered', 1], [$retry->status, $retry->retry_sequence]);
        $this->api($token, 'GET', "/api/v1/projects/{$this->project->id}/configuration/applications/{$application}")->assertJsonPath('data.status', 'deploying');

        // A lock holds the next one; the dispatcher starts it once the lock goes.
        $staging = Environment::query()->where('slug', 'staging')->sole();
        Build::query()->where('status', Build::STATUS_RUNNING)->update(['status' => Build::STATUS_SUCCEEDED]);
        $staging->forceFill(['deployment_locked_at' => now(), 'build_command' => 'npm run build:prod'])->save();
        $this->api($token, 'POST', "/api/v1/projects/{$this->project->id}/configuration/reviews", ['document' => str_replace('npm start', 'npm run serve', $this->document()), 'bindings' => json_decode($this->bindings(), true)])->assertCreated();
        $next = ConfigurationReview::query()->latest('id')->firstOrFail();
        $this->api($token, 'POST', "/api/v1/projects/{$this->project->id}/configuration/reviews/{$next->id}/apply")->assertOk()->assertJsonPath('data.status', 'needs_attention')->assertJsonPath('data.operations.0.failure_code', 'deployment_gate');
        $staging->forceFill(['deployment_locked_at' => null])->save();
        $this->command('configuration:dispatch')->expectsOutput('Started 1 configuration deploys.');
    }

    private function document(bool $deploy = true, bool $adopt = false): string
    {
        return "version: 2\nenvironments:\n  staging:\n    type: staging\n    placement: web\n".($adopt ? "    adopt: true\n" : '')
            ."    runtime:\n      type: node\n      start_command: npm start\n      port: 3000\n"
            ."    processes:\n      queue:\n        type: worker\n        command: node worker.js\n        replicas: 2\n"
            ."    resources:\n      database:\n        type: mysql\n        managed: true\n"
            ."    variables:\n      STRIPE_SECRET:\n        secret_ref: stripe\n        scope: runtime\n"
            .($deploy ? "    deploy:\n      repository: app\n" : '');
    }

    private function bindings(): string
    {
        return json_encode(['placements' => ['web' => $this->website->id], 'repositories' => ['app' => $this->repository->id], 'secrets' => ['stripe' => $this->secret->id]], JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function api(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withToken($token)->json($method, $uri, $data);
    }
}
