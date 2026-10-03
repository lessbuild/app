<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Actions\ApiTokens\CreateApiToken;
use App\Data\ApiTokens\CreateApiTokenData;
use App\Enums\AccountRole;
use App\Enums\ApiScope;
use App\Enums\EnvironmentKind;
use App\Enums\ProviderType;
use App\Models\Build;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Notifications\BuildAwaitingApproval;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class PromotionAndApiTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    private const REVISION = 'abcdefabcdefabcdefabcdefabcdefabcdefabcd';

    private Project $project;

    private User $owner;

    private Environment $staging;

    private Environment $production;

    private Repository $stagingRepository;

    private Repository $productionRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $this->project = Project::factory()->withServices(['deploy'])->create();
        $this->owner = $this->ownerOf($this->project);
        $this->onTier($this->project, 'deploy', 'business');
        $this->production = $this->project->environments()->where('slug', 'production')->firstOrFail();
        $this->staging = Environment::factory()->create(['project_id' => $this->project->id, 'name' => 'Staging', 'slug' => 'staging', 'kind' => EnvironmentKind::Staging]);
        $github = Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $this->project->account_id]);
        $server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $this->project->account_id])->id]);
        foreach (['staging' => $this->staging, 'production' => $this->production] as $name => $environment) {
            $website = Website::factory()->create(['server_id' => $server->id, 'name' => ucfirst($name)]);
            $this->{$name.'Repository'} = Repository::factory()->create(['project_id' => $this->project->id, 'website_id' => $website->id, 'provider_id' => $github->id, 'environment_id' => $environment->id]);
        }
        $this->withoutMiddleware(RequirePassword::class);
    }

    public function test_a_staging_build_is_promoted_to_production_with_its_approval(): void
    {
        Notification::fake();
        $this->production->forceFill(['requires_deployment_approval' => true])->save();
        $member = User::factory()->create();
        $this->addMember($this->project, $member, AccountRole::Member);
        $source = Build::factory()->succeeded(self::REVISION)->create(['repository_id' => $this->stagingRepository->id, 'environment_id' => $this->staging->id, 'commit_message' => 'Checkout v2']);

        $base = "/projects/{$this->project->id}/deploy/builds";
        $this->actingAs($this->owner)->get("{$base}/{$source->id}")->assertSee('Promote this commit to')->assertSee('Production');
        $this->actingAs($this->owner)->post("{$base}/{$source->id}/promote", ['environment_id' => $this->production->id, 'note' => 'QA passed'])->assertRedirect();

        $promotion = Build::query()->latest('id')->firstOrFail();
        $this->assertSame([Build::STATUS_AWAITING_APPROVAL, 'promotion', self::REVISION, $source->id, 'QA passed', $this->productionRepository->id], [
            $promotion->status, $promotion->trigger_source, $promotion->revision, $promotion->promoted_from_build_id, $promotion->promotion_note, $promotion->repository_id,
        ]);
        Notification::assertSentTo($member, BuildAwaitingApproval::class, fn (BuildAwaitingApproval $notification): bool => $notification->toArray($member)['title'] === "Deploy #{$promotion->id} to Production needs approval");
        Notification::assertNotSentTo($this->owner, BuildAwaitingApproval::class);
        $this->actingAs($this->owner)->get("{$base}/{$source->id}")->assertSee("#{$promotion->id}");

        // Backwards, sideways and unfinished builds don't promote.
        $this->actingAs($this->owner)->post("{$base}/{$promotion->id}/promote", ['environment_id' => $this->staging->id])->assertSessionHasErrors('promote');
        $failed = Build::factory()->create(['repository_id' => $this->stagingRepository->id, 'environment_id' => $this->staging->id, 'status' => Build::STATUS_FAILED, 'revision' => self::REVISION]);
        $this->actingAs($this->owner)->post("{$base}/{$failed->id}/promote", ['environment_id' => $this->production->id])->assertSessionHasErrors('promote');
    }

    public function test_the_deployer_api_reads_and_deploys_with_scoped_tokens(): void
    {
        $build = Build::factory()->succeeded(self::REVISION)->create(['repository_id' => $this->stagingRepository->id, 'environment_id' => $this->staging->id, 'log' => "all good\n"]);
        $read = $this->token([ApiScope::DeployRead]);
        $write = $this->token([ApiScope::DeployWrite]);

        $this->api($read, 'GET', '/api/v1/me')->assertOk()->assertJsonPath('data.email', $this->owner->email)->assertJsonPath('data.organization.plan', 'business');
        $this->api($read, 'GET', '/api/v1/projects')->assertOk()->assertJsonPath('data.0.id', $this->project->id)->assertJsonCount(2, 'data.0.environments');
        $this->api($read, 'GET', "/api/v1/projects/{$this->project->id}")->assertOk()->assertJsonPath('data.name', $this->project->name);
        $this->api($read, 'GET', '/api/v1/deployments')->assertOk()->assertJsonPath('data.0.id', $build->id)->assertJsonPath('data.0.revision', self::REVISION);
        $this->api($read, 'GET', '/api/v1/deployments?limit=1')->assertOk()->assertJsonPath('meta.limit', 1);
        $this->api($read, 'GET', "/api/v1/deployments/{$build->id}/log")->assertOk()->assertJsonPath('data.log', "all good\n")->assertHeader('Cache-Control', 'no-store, private');
        $this->api($read, 'POST', "/api/v1/environments/{$this->staging->id}/deploy")->assertForbidden();

        $this->api($write, 'POST', "/api/v1/environments/{$this->staging->id}/deploy")->assertStatus(202)->assertJsonPath('data.trigger', 'api')->assertJsonPath('data.status', Build::STATUS_QUEUED);
        $this->api($write, 'POST', "/api/v1/environments/{$this->staging->id}/deploy")->assertStatus(409);
        $this->api($write, 'POST', "/api/v1/deployments/{$build->id}/promote", ['target_environment_id' => $this->production->id])->assertStatus(202)->assertJsonPath('data.deployment.promoted_from_build_id', $build->id);
        $this->api($write, 'PUT', "/api/v1/environments/{$this->production->id}/variables", ['variables' => "APP_NAME=Shop\nQUEUE=redis"])->assertOk()->assertJsonPath('data.count', 2);
        $this->production->forceFill(['maximum_replicas' => 4])->save();
        $this->api($write, 'PATCH', "/api/v1/environments/{$this->production->id}/scale", ['replicas' => 3])->assertStatus(202)->assertJsonPath('data.desired_replicas', 3);
        $this->api($write, 'PATCH', "/api/v1/environments/{$this->production->id}/scale", ['replicas' => 9])->assertUnprocessable();
        $this->assertSame(3, $this->production->refresh()->desired_replicas);

        // Records outside the token's account aren't there.
        $foreign = Build::factory()->create();
        $this->api($read, 'GET', "/api/v1/deployments/{$foreign->id}")->assertNotFound();
        $this->api($read, 'GET', '/api/v1/projects/'.Project::factory()->create()->id)->assertNotFound();
    }

    /** @param list<ApiScope> $scopes */
    private function token(array $scopes): string
    {
        return app(CreateApiToken::class)->handle($this->owner, $this->project->account, new CreateApiTokenData('ci', $scopes, 30))->plainText;
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
