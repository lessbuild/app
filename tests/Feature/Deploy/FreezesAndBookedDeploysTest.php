<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Enums\ProviderType;
use App\Models\Build;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\ScheduledDeploy;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class FreezesAndBookedDeploysTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * The project being deployed.
     *
     * @var Project
     */
    private Project $project;

    /**
     * Its owner.
     *
     * @var User
     */
    private User $owner;

    /**
     * Its production environment.
     *
     * @var Environment
     */
    private Environment $production;

    /**
     * The repository that deploys to production.
     *
     * @var Repository
     */
    private Repository $repository;

    /**
     * Set up a repository on a live website, on a plan with scheduled deploys.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $this->travelTo(CarbonImmutable::parse('2026-12-01 10:00:00', 'UTC'));
        $this->project = Project::factory()->withServices(['deploy', 'infrastructure'])->create();
        $this->owner = $this->ownerOf($this->project);
        $this->onTier($this->project, 'deploy', 'pro');
        $server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $this->project->account_id])->id]);
        $website = Website::factory()->create(['server_id' => $server->id, 'deployment_slug' => 'shop']);
        $github = Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $this->project->account_id, 'token' => 'ghp_secret']);
        $this->production = $this->project->environments()->where('slug', 'production')->firstOrFail();
        $this->repository = Repository::factory()->create(['website_id' => $website->id, 'project_id' => $this->project->id, 'provider_id' => $github->id, 'environment_id' => $this->production->id]);
        $this->withoutMiddleware(RequirePassword::class);
    }

    /**
     * Check that a freeze blocks deploys while it lasts, in its own time zone, and can be ended early.
     *
     * @return void
     */
    public function test_a_freeze_blocks_deploys_while_it_lasts(): void
    {
        $base = "/projects/{$this->project->id}/deploy/environments/{$this->production->id}";
        $this->actingAs($this->owner)->post("{$base}/freezes", ['starts_at' => '2026-12-01T12:00', 'ends_at' => '2026-12-01T11:00', 'timezone' => 'Europe/London'])->assertSessionHasErrors('ends_at');
        $this->actingAs($this->owner)->post("{$base}/freezes", ['starts_at' => '2026-12-01T12:00', 'ends_at' => '2027-01-02T09:00', 'timezone' => 'Europe/London', 'reason' => 'Holiday freeze'])
            ->assertRedirect("{$base}?tab=controls");
        $freeze = $this->production->freezes()->sole();
        $this->assertSame('2027-01-02 09:00:00', $freeze->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->actingAs($this->owner)->get("{$base}?tab=controls")->assertOk()->assertSee('Holiday freeze');

        $deploy = "/projects/{$this->project->id}/deploy/repositories/{$this->repository->id}/builds";
        $this->travelTo(CarbonImmutable::parse('2026-12-24 10:00:00', 'UTC'));
        $this->actingAs($this->owner)->post($deploy)->assertSessionHasErrors('deploy');
        $this->assertStringContainsString('Holiday freeze', (string) session('errors')?->first('deploy'));

        $this->actingAs($this->owner)->delete("{$base}/freezes/{$freeze->id}")->assertRedirect("{$base}?tab=controls");
        $this->actingAs($this->owner)->post($deploy)->assertRedirect();
        $this->assertSame(1, Build::query()->count());
    }

    /**
     * Check that a booked deploy runs once at its time, as the person who booked it and with its ref, and that a booked
     * deploy can be cancelled.
     *
     * @return void
     */
    public function test_a_booked_deploy_runs_at_its_time(): void
    {
        $base = "/projects/{$this->project->id}/deploy/repositories/{$this->repository->id}";
        $this->actingAs($this->owner)->post("{$base}/scheduled-deploys", ['deploy_at' => '2026-11-30T02:00', 'timezone' => 'UTC'])->assertSessionHasErrors('deploy_at');
        $this->actingAs($this->owner)->post("{$base}/scheduled-deploys", ['deploy_at' => '2026-12-02T02:00', 'timezone' => 'America/New_York', 'ref' => 'v2.0.0'])->assertRedirect($base);
        $this->actingAs($this->owner)->post("{$base}/scheduled-deploys", ['deploy_at' => '2026-12-03T02:00', 'timezone' => 'UTC'])->assertRedirect($base);
        [$first, $second] = ScheduledDeploy::query()->orderBy('run_at')->get()->all();
        $this->assertSame('2026-12-02 07:00:00', $first->run_at->utc()->format('Y-m-d H:i:s'));
        $this->actingAs($this->owner)->get($base)->assertOk()->assertSee('Booked deploys')->assertSee('v2.0.0');

        $this->actingAs($this->owner)->delete("{$base}/scheduled-deploys/{$second->id}")->assertRedirect($base);
        $this->assertSame('cancelled', $second->refresh()->status);

        $this->travelTo(CarbonImmutable::parse('2026-12-02 06:59:00', 'UTC'));
        $this->command('automation:dispatch')->assertSuccessful();
        $this->assertSame(0, Build::query()->count());
        $this->travelTo(CarbonImmutable::parse('2026-12-02 07:00:00', 'UTC'));
        $this->command('automation:dispatch')->assertSuccessful();
        $build = Build::query()->sole();
        $this->assertSame(['scheduled', 'v2.0.0', $this->owner->id], [$build->trigger_source, $build->git_ref, $build->requested_by]);
        $this->assertSame(['done', $build->id], [$first->refresh()->status, $first->build_id]);
        $this->command('automation:dispatch')->assertSuccessful();
        $this->assertSame(1, Build::query()->count());
    }
}
