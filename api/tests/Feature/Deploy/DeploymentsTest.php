<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Enums\AccountRole;
use App\Enums\ProviderType;
use App\Models\Build;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\Infrastructure\ProvisioningCallbackUrl;
use App\Support\Deploy\ReleaseNotes;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class DeploymentsTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    private const REVISION = '0123456789abcdef0123456789abcdef01234567';

    private Project $project;

    private User $owner;

    private Website $website;

    private Provider $github;

    private Environment $production;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $this->project = Project::factory()->withServices(['deploy', 'infrastructure', 'monitoring'])->create();
        $this->owner = $this->ownerOf($this->project);
        $this->onTier($this->project, 'deploy', 'pro');
        $server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $this->project->account_id])->id]);
        $this->website = Website::factory()->create(['server_id' => $server->id, 'name' => 'Shop', 'deployment_slug' => 'shop']);
        $this->github = Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $this->project->account_id, 'token' => 'ghp_secret']);
        $this->production = $this->project->environments()->where('slug', 'production')->firstOrFail();
        $this->base = "/api/app/projects/{$this->project->id}/deploy";
        $this->withoutMiddleware(RequirePassword::class);
    }

    /**
     * A repository deploys through the script and its callbacks.
     */
    public function test_a_repository_deploys_through_the_script_and_its_callbacks(): void
    {
        $this->actingAs($this->owner)->postJson("{$this->base}/repositories", $this->repository(['provider_id' => Provider::factory()->type(ProviderType::GitLab)->create(['account_id' => $this->project->account_id])->id]))->assertJsonValidationErrors('url');
        $this->actingAs($this->owner)->postJson("{$this->base}/repositories", $this->repository(['url' => 'https://github.com/Acme/Shop.git', 'build_commands' => "npm ci\nnpm run build"]))->assertSuccessful();
        $repository = Repository::query()->sole();
        $this->assertSame('github.com/acme/shop', $repository->url);

        $this->actingAs($this->owner)->postJson("{$this->base}/repositories/{$repository->id}/builds")->assertSuccessful();
        $build = Build::query()->sole();
        $this->assertSame([Build::STATUS_RUNNING, 4242, $this->owner->id], [$build->status, $build->remote_process_id, $build->requested_by]);
        $this->assertSame("APP_ENV=production\n", $build->environment_payload['base_environment'] ?? null);
        $script = $this->scripts->started[0]['script'];
        $this->assertStringContainsString("git clone -- 'https://github.com/acme/shop' '/var/www/shop/setup'", $script);
        $this->assertStringContainsString(base64_encode("machine github.com\nlogin x-access-token\npassword ghp_secret\n"), $script);
        $this->assertStringContainsString("/builds/{$build->id}/deployment/callback/status", $script);
        $this->assertStringContainsString('npm run build', $script);
        $this->assertStringContainsString('mv -Tf -- "$NEXT_LINK" "$CURRENT_PATH"', $script);

        // A second deploy while one runs is refused.
        $this->actingAs($this->owner)->postJson("{$this->base}/repositories/{$repository->id}/builds")->assertStatus(409);

        $this->post(ProvisioningCallbackUrl::buildRevision($build), ['revision' => self::REVISION, 'commit_message' => "Ship it\n\nDetails"])->assertNoContent();
        $this->post(ProvisioningCallbackUrl::buildLog($build), ['log' => "Cloning…\nDone\n"])->assertNoContent();
        foreach (range(1, 14) as $stage) {
            $this->post(ProvisioningCallbackUrl::buildStatus($build), ['status' => $stage])->assertNoContent();
        }
        $this->assertSame(Build::STATUS_RUNNING, $this->reload($build)->status);
        $this->assertNotNull($this->reload($build)->activated_at);
        $this->post(ProvisioningCallbackUrl::buildStatus($build), ['status' => 15])->assertNoContent();
        $build = $this->reload($build);
        $this->assertSame([Build::STATUS_SUCCEEDED, self::REVISION, "Ship it\n\nDetails", "Cloning…\nDone\n"], [$build->status, $build->revision, $build->commit_message, $build->log]);

        $marker = Deployment::query()->sole();
        $this->assertSame([$this->production->id, 'deploy', self::REVISION, '0123456789ab'], [$marker->environment_id, $marker->source, $marker->commit_sha, $marker->release->version]);

        $this->post("/builds/{$build->id}/deployment/callback/status", ['status' => 1])->assertForbidden();
        $this->actingAs($this->owner)->getJson("{$this->base}/builds/{$build->id}")->assertOk()->assertJsonPath('build.status', Build::STATUS_SUCCEEDED)->assertJsonPath('build.revision', self::REVISION);
        $this->actingAs($this->owner)->getJson($this->base)->assertOk()->assertJsonPath('repositories.0.latestBuild.status', Build::STATUS_SUCCEEDED);
    }

    /**
     * Check a deploy reports its commits since the last live release, the deploy page shows them as grouped release
     * notes (chores and merges left out), the next deploy's script asks git for commits since this one, and the
     * notes can be published on a public page and taken down.
     *
     * @return void
     */
    public function test_release_notes_are_written_from_the_deploys_commits(): void
    {
        $this->actingAs($this->owner)->postJson("{$this->base}/repositories", $this->repository(['url' => 'https://github.com/Acme/Shop.git']))->assertSuccessful();
        $repository = Repository::query()->sole();
        $this->actingAs($this->owner)->postJson("{$this->base}/repositories/{$repository->id}/builds")->assertSuccessful();
        $build = Build::query()->sole();
        $this->assertStringContainsString("log --no-merges --format='%h%x1f%an%x1f%s' -n 30 HEAD", $this->scripts->started[0]['script'], 'The first deploy takes the last 30 commits.');
        $commits = implode("\n", ["a1b2c3d\x1fAda\x1ffeat(cart): save carts between visits (#12)", "b2c3d4e\x1fLin\x1ffix: rounding on totals", "c3d4e5f\x1fAda\x1fchore: bump deps", "d4e5f6a\x1fLin\x1fTidy the checkout copy"]);
        $this->post(ProvisioningCallbackUrl::buildRevision($build), ['revision' => self::REVISION, 'commit_message' => 'Ship it', 'commits' => $commits])->assertNoContent();
        foreach (range(1, 15) as $stage) {
            $this->post(ProvisioningCallbackUrl::buildStatus($build), ['status' => $stage])->assertNoContent();
        }
        $this->assertCount(4, (array) $this->reload($build)->release_commits);
        $this->actingAs($this->owner)->getJson("{$this->base}/builds/{$build->id}")->assertOk()
            ->assertSeeInOrder(['Save carts between visits (#12)', 'Rounding on totals', 'Tidy the checkout copy'])->assertDontSee('bump deps');
        $this->assertStringContainsString("Features:\n• Save carts between visits (#12)", (string) ReleaseNotes::text($this->reload($build)->release_commits ?? []));

        $this->actingAs($this->owner)->postJson("{$this->base}/repositories/{$repository->id}/builds")->assertSuccessful();
        $this->assertStringContainsString("'".self::REVISION."..HEAD'", $this->scripts->started[1]['script'], 'The next deploy asks for commits since the live one.');

        // The callbacks above ran signed in, so they share the person's rate limit; start a new minute.
        $this->travel(2)->minutes();
        $environmentUrl = "{$this->base}/environments/{$this->production->id}/release-notes";
        $this->actingAs($this->owner)->putJson($environmentUrl)->assertSuccessful();
        $token = (string) $this->production->refresh()->release_notes_token;
        $this->getJson("/api/app/releases/{$token}")->assertOk()->assertSee('Save carts between visits (#12)')->assertDontSee('Ada');
        $this->actingAs($this->owner)->deleteJson($environmentUrl)->assertSuccessful();
        $this->get("/releases/{$token}")->assertNotFound();
    }

    /**
     * A failed script is recorded and stale deploys are reaped.
     */
    public function test_a_failed_script_is_recorded_and_stale_deploys_are_reaped(): void
    {
        $build = Build::factory()->create(['repository_id' => Repository::factory()->create(['website_id' => $this->website->id, 'project_id' => $this->project->id, 'provider_id' => $this->github->id])->id, 'status' => Build::STATUS_RUNNING, 'last_heartbeat_at' => now()]);
        $this->post(ProvisioningCallbackUrl::buildFailure($build), ['message' => 'Remote deployment script failed', 'exit_code' => 2])->assertNoContent();
        $this->assertSame([Build::STATUS_FAILED, 'Remote deployment script failed (exit code 2)'], [$this->reload($build)->status, $this->reload($build)->failure_message]);
        $this->assertSame(0, Deployment::query()->count());

        $stale = Build::factory()->create(['repository_id' => $build->repository_id, 'status' => Build::STATUS_RUNNING, 'last_heartbeat_at' => now()->subMinutes(30)]);
        $this->command('builds:reap')->expectsOutput('Failed 1 stalled deploys.');
        $this->assertSame('The deployment stopped reporting from the server.', $this->reload($stale)->failure_message);
    }

    /**
     * Push webhooks deploy the branch once and wait behind a running deploy.
     */
    public function test_push_webhooks_deploy_the_branch_once_and_wait_behind_a_running_deploy(): void
    {
        $repository = Repository::factory()->create(['website_id' => $this->website->id, 'project_id' => $this->project->id, 'provider_id' => $this->github->id, 'auto_deploy_exclude_paths' => ['docs/**']]);
        $this->actingAs($this->owner)->postJson("{$this->base}/repositories/{$repository->id}/webhook")->assertOk()->assertJsonStructure(['secret', 'url']);
        $secret = (string) $this->reload($repository)->webhook_secret;

        $this->push($repository, 'bad-secret', 'd-1')->assertStatus(401)->assertJson(['status' => 'unauthorized']);
        $this->push($repository, $secret, 'd-1', ['ref' => 'refs/heads/develop'])->assertOk()->assertJson(['status' => 'branch_ignored']);
        $this->push($repository, $secret, 'd-2', ['commits' => [['modified' => ['docs/readme.md']]]])->assertOk()->assertJson(['status' => 'skipped']);
        $this->push($repository, $secret, 'd-3')->assertStatus(202)->assertJson(['status' => 'queued']);
        $this->push($repository, $secret, 'd-3')->assertOk()->assertJson(['status' => 'duplicate']);
        $build = Build::query()->sole();
        $this->assertSame(['webhook', self::REVISION, 'Fix checkout'], [$build->trigger_source, $build->revision, $build->commit_message]);

        $next = str_repeat('b', 40);
        $this->push($repository, $secret, 'd-4', ['after' => $next, 'head_commit' => ['message' => 'Next']])->assertStatus(202)->assertJson(['status' => 'pending']);
        $this->assertTrue($this->reload($repository)->webhook_pending);
        $this->post(ProvisioningCallbackUrl::buildStatus($build), ['status' => 15])->assertNoContent();
        $this->assertSame($next, Build::query()->latest('id')->firstOrFail()->revision);
        $this->assertFalse($this->reload($repository)->webhook_pending);

        $this->actingAs($this->owner)->deleteJson("{$this->base}/repositories/{$repository->id}/webhook");
        $this->push($repository, $secret, 'd-5')->assertNotFound();
    }

    /**
     * Deploys to an environment that needs approval wait for someone else.
     */
    public function test_deploys_to_an_environment_that_needs_approval_wait_for_someone_else(): void
    {
        $this->production->forceFill(['requires_deployment_approval' => true])->save();
        $repository = Repository::factory()->create(['website_id' => $this->website->id, 'project_id' => $this->project->id, 'provider_id' => $this->github->id, 'environment_id' => $this->production->id]);
        $this->actingAs($this->owner)->postJson("{$this->base}/repositories/{$repository->id}/builds");
        $build = Build::query()->sole();
        $this->assertSame(Build::STATUS_AWAITING_APPROVAL, $build->status);
        $this->assertSame([], $this->scripts->started);

        $this->actingAs($this->owner)->postJson("{$this->base}/builds/{$build->id}/review", ['decision' => 'approve'])->assertForbidden();
        $member = User::factory()->create();
        $this->addMember($this->project, $member, AccountRole::Member);
        $this->actingAs($member)->getJson("{$this->base}/builds/{$build->id}")->assertJsonPath('canApprove', true);
        $this->actingAs($member)->postJson("{$this->base}/builds/{$build->id}/review", ['decision' => 'approve', 'note' => 'Checked staging'])->assertSuccessful();
        $this->assertSame([Build::STATUS_RUNNING, $member->id, 'Checked staging'], [$this->reload($build)->status, $this->reload($build)->approved_by, $this->reload($build)->approval_note]);

        $this->post(ProvisioningCallbackUrl::buildStatus($build), ['status' => 15]);
        $this->actingAs($this->owner)->postJson("{$this->base}/repositories/{$repository->id}/builds");
        $second = Build::query()->latest('id')->firstOrFail();
        $this->actingAs($member)->postJson("{$this->base}/builds/{$second->id}/review", ['decision' => 'reject'])->assertSuccessful();
        $this->assertSame(Build::STATUS_REJECTED, $this->reload($second)->status);
    }

    /**
     * Rollback redeploy and cancel.
     */
    public function test_rollback_redeploy_and_cancel(): void
    {
        $repository = Repository::factory()->create(['website_id' => $this->website->id, 'project_id' => $this->project->id, 'provider_id' => $this->github->id]);
        $old = Build::factory()->succeeded()->create(['repository_id' => $repository->id]);

        $this->actingAs($this->owner)->postJson("{$this->base}/builds/{$old->id}/rollback")->assertSuccessful();
        $rollback = Build::query()->latest('id')->firstOrFail();
        $this->assertSame([Build::STATUS_SUCCEEDED, 'rollback', $old->id, $old->release_name], [$rollback->status, $rollback->trigger_source, $rollback->rolled_back_from_build_id, $rollback->release_name]);
        $this->assertStringContainsString("TARGET_PATH='/var/www/shop/releases/{$old->release_name}'", (string) (collect($this->shell->ran)->last()['command'] ?? ''));

        $this->actingAs($this->owner)->postJson("{$this->base}/builds/{$old->id}/redeploy")->assertSuccessful();
        $redeploy = Build::query()->latest('id')->firstOrFail();
        $this->assertSame([Build::STATUS_RUNNING, $old->revision, $old->id], [$redeploy->status, $redeploy->revision, $redeploy->redeployed_from_build_id]);
        $this->assertStringContainsString("merge-base --is-ancestor '{$old->revision}'", (string) (collect($this->scripts->started)->last()['script'] ?? ''));

        $this->shell->reply("partial log\n");
        $this->actingAs($this->owner)->postJson("{$this->base}/builds/{$redeploy->id}/cancel")->assertSuccessful();
        $this->assertSame([Build::STATUS_CANCELED, "partial log\n"], [$this->reload($redeploy)->status, $this->reload($redeploy)->log]);
        $this->assertStringContainsString('kill -TERM', (string) (collect($this->shell->ran)->last()['command'] ?? ''));
    }

    /**
     * Viewers see deploys but cannot start them.
     */
    public function test_viewers_see_deploys_but_cannot_start_them(): void
    {
        $repository = Repository::factory()->create(['website_id' => $this->website->id, 'project_id' => $this->project->id, 'provider_id' => $this->github->id]);
        $viewer = User::factory()->create();
        $this->addMember($this->project, $viewer, AccountRole::Viewer);
        $this->actingAs($viewer)->getJson("{$this->base}/repositories/{$repository->id}")->assertOk()->assertJsonPath('canDeploy', false);
        $this->actingAs($viewer)->postJson("{$this->base}/repositories/{$repository->id}/builds")->assertForbidden();

        $foreign = Repository::factory()->create();
        $this->actingAs($this->owner)->getJson("{$this->base}/repositories/{$foreign->id}")->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function repository(array $overrides = []): array
    {
        return ['name' => 'shop', 'provider_id' => $this->github->id, 'url' => 'github.com/acme/shop', 'branch' => 'main', 'website_id' => $this->website->id, 'environment_id' => $this->production->id, ...$overrides];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function push(Repository $repository, string $secret, string $delivery, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        $body = json_encode(['ref' => 'refs/heads/main', 'after' => self::REVISION, 'head_commit' => ['message' => 'Fix checkout'], 'commits' => [['modified' => ['app/Checkout.php']]], ...$overrides], JSON_THROW_ON_ERROR);

        return $this->call('POST', "/api/repositories/{$repository->id}/webhook", [], [], [], [
            'HTTP_X_GITHUB_EVENT' => 'push', 'HTTP_X_GITHUB_DELIVERY' => $delivery, 'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
        ], $body);
    }
}
