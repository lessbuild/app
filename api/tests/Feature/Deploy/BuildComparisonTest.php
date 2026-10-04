<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Enums\ProviderType;
use App\Models\Build;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class BuildComparisonTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    private const OLD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const NEW = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private Project $project;

    private User $owner;

    private Repository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $this->project = Project::factory()->withServices(['deploy'])->create();
        $this->owner = $this->ownerOf($this->project);
        $github = Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $this->project->account_id]);
        $server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $this->project->account_id])->id]);
        $website = Website::factory()->create(['server_id' => $server->id]);
        $this->repository = Repository::factory()->create(['project_id' => $this->project->id, 'website_id' => $website->id, 'provider_id' => $github->id, 'url' => 'github.com/acme/shop']);
    }

    /**
     * A deploy is compared with the last good one and secrets stay hidden.
     */
    public function test_a_deploy_is_compared_with_the_last_good_one_and_secrets_stay_hidden(): void
    {
        $payload = fn (array $runtime, array $variables, array $processes, string $env): array => [
            'base_environment' => $env, 'repository_root' => '.', 'runtime' => $runtime, 'variables' => $variables, 'build_variables' => [],
            'processes' => $processes, 'resources' => [],
        ];
        $baseline = Build::factory()->succeeded(self::OLD)->create(['repository_id' => $this->repository->id, 'commit_message' => 'Old checkout',
            'environment_payload' => $payload(['deployment_strategy' => 'atomic'], ['APP_KEY' => 'secret-one', 'OLD_FLAG' => '1'], [['name' => 'queue', 'command' => 'php artisan queue:work']], 'A=1')]);
        Build::factory()->create(['repository_id' => $this->repository->id, 'status' => Build::STATUS_FAILED]);
        $build = Build::factory()->succeeded(self::NEW)->create(['repository_id' => $this->repository->id, 'commit_message' => 'New checkout',
            'environment_payload' => $payload(['deployment_strategy' => 'blue_green'], ['APP_KEY' => 'secret-two', 'NEW_FLAG' => '1'], [['name' => 'queue', 'command' => 'php artisan queue:work --tries=3']], 'A=2')]);

        $base = "/api/app/projects/{$this->project->id}/deploy/builds";
        $this->actingAs($this->owner)->getJson("{$base}/{$build->id}")->assertOk()->assertJsonPath('build.id', $build->id);
        $this->actingAs($this->owner)->getJson("{$base}/{$build->id}/compare")->assertOk()
            ->assertJsonPath('baseline.id', $baseline->id)
            ->assertJsonPath('comparison.compareUrl', 'https://github.com/acme/shop/compare/'.self::OLD.'...'.self::NEW)
            ->assertJsonPath('baseline.commitMessage', 'Old checkout')->assertJsonPath('build.commitMessage', 'New checkout')
            ->assertSee('blue_green')->assertSee('APP_KEY')->assertSee('OLD_FLAG')->assertSee('NEW_FLAG')->assertSee('queue:work --tries=3')->assertSee('.env')
            ->assertDontSee('secret-one')->assertDontSee('secret-two')->assertDontSee('A=2');
    }

    /**
     * The baseline can be chosen only from the same repository.
     */
    public function test_the_baseline_can_be_chosen_only_from_the_same_repository(): void
    {
        $first = Build::factory()->succeeded(self::OLD)->create(['repository_id' => $this->repository->id]);
        $build = Build::factory()->succeeded(self::OLD)->create(['repository_id' => $this->repository->id]);
        $elsewhere = Build::factory()->succeeded()->create();

        $base = "/api/app/projects/{$this->project->id}/deploy/builds/{$build->id}/compare";
        $this->actingAs($this->owner)->getJson("{$base}?with={$first->id}")->assertOk()->assertJsonPath('baseline.id', $first->id)->assertJsonPath('comparison.compareUrl', null);
        $this->actingAs($this->owner)->getJson("{$base}?with={$elsewhere->id}")->assertNotFound();
        $this->actingAs(User::factory()->create())->getJson($base)->assertNotFound();

        $alone = Build::factory()->succeeded()->create(['repository_id' => Repository::factory()->create(['project_id' => $this->project->id, 'website_id' => $this->repository->website_id])->id]);
        $this->actingAs($this->owner)->getJson("/api/app/projects/{$this->project->id}/deploy/builds/{$alone->id}/compare")->assertOk()->assertJsonPath('baseline', null)->assertJsonPath('comparison', null);
    }
}
