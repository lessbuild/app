<?php

namespace Tests\Feature;

use App\Jobs\Web\CleanupWebsitePlacementJob;
use App\Models\Build;
use App\Models\Provider;
use App\Models\Server;
use App\Models\ServerLogSnapshot;
use App\Models\User;
use App\Models\Website;
use App\Services\PreviewDeploymentLifecycle;
use App\Services\ProvisioningCallbackUrl;
use App\Services\RepositoryDeploymentPlan;
use App\Services\ServerProvisioningPlan;
use App\Services\WebsiteProvisioningPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProvisioningCallbackConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Notification::fake();
    }

    #[DataProvider('interleavings')]
    public function test_callbacks_recheck_the_attempt_and_terminal_state_after_route_binding(string $type, string $endpoint, string $change): void
    {
        $target = $this->target($type);
        $url = $this->signedCallbackUrl($target, $endpoint);
        $attributes = match ($change) {
            'retry' => ['provisioning_token' => 'new-attempt', 'provisioning_status' => 'queued', 'setup_stage' => 0],
            'failed' => ['provisioning_status' => 'failed', 'provisioning_error' => 'Another callback failed first'],
            'active' => ['provisioning_status' => 'active', 'provisioned_at' => now()],
        };
        $expected = null;

        // Suspend the first request after binding, then persist the competing change
        // before the controller resumes with its deliberately stale model snapshot.
        Route::bind($type, function (string $id) use ($target, $attributes, &$expected): Server|Website {
            $bound = $target->newQuery()->findOrFail($id);
            $bound->newQuery()->whereKey($id)->update($attributes);
            $expected = $bound->fresh()->getRawOriginal();

            return $bound;
        });
        $lifecycle = $this->mock(PreviewDeploymentLifecycle::class);
        $lifecycle->shouldNotReceive('websiteReady');
        $lifecycle->shouldNotReceive('websiteFailed');

        $this->post($url, $endpoint === 'status'
            ? ['status' => (string) $this->finalStage($target)]
            : ['message' => 'Late callback failure'])->assertNoContent();

        $this->assertNotNull($expected);
        $this->assertSame($expected, $target->fresh()->getRawOriginal());
        $this->assertDatabaseCount('server_log_snapshots', 0);
        Queue::assertNothingPushed();
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function interleavings(): iterable
    {
        foreach (['server', 'website'] as $type) {
            foreach (['status', 'failed'] as $endpoint) {
                foreach (['retry', 'failed', 'active'] as $change) {
                    yield "{$type} {$endpoint} after {$change}" => [$type, $endpoint, $change];
                }
            }
        }
    }

    #[DataProvider('targets')]
    public function test_progress_from_a_stale_binding_cannot_decrease_the_saved_stage(string $type): void
    {
        $target = $this->target($type);
        Route::bind($type, function (string $id) use ($target): Server|Website {
            $bound = $target->newQuery()->findOrFail($id);
            $bound->newQuery()->whereKey($id)->update(['setup_stage' => 2]);

            return $bound;
        });

        $this->post($this->signedCallbackUrl($target, 'status'), ['status' => '1'])->assertStatus(200);

        $this->assertSame(2, $target->fresh()->setup_stage);
        $this->assertSame('provisioning', $target->fresh()->provisioning_status);
    }

    #[DataProvider('targets')]
    public function test_form_string_completion_and_duplicate_callbacks_transition_once(string $type): void
    {
        $target = $this->target($type);
        if ($target instanceof Website) {
            $previous = $target->user->servers()->create(['name' => 'Previous placement', 'provisioning_status' => 'active']);
            $target->updateQuietly(['previous_server_id' => $previous->id]);
            $this->mock(PreviewDeploymentLifecycle::class)->shouldReceive('websiteReady')->once()
                ->withArgs(function (Website $website) use ($target): bool {
                    $this->assertGreaterThan(1, DB::transactionLevel());

                    return $website->is($target) && $website->provisioning_status === Website::STATUS_ACTIVE;
                });
        }
        $url = $this->signedCallbackUrl($target, 'status');
        $stage = (string) $this->finalStage($target);

        $this->post($url, ['status' => $stage])->assertStatus(200);
        $completed = $target->fresh()->getRawOriginal();
        $this->post($url, ['status' => $stage])->assertNoContent();
        $this->post($this->signedCallbackUrl($target, 'failed'), ['message' => 'Late failure'])->assertNoContent();

        $this->assertSame('active', $target->fresh()->provisioning_status);
        $this->assertNotNull($target->fresh()->provisioned_at);
        $this->assertSame($completed, $target->fresh()->getRawOriginal());
        if ($target instanceof Website) {
            Queue::assertPushedTimes(CleanupWebsitePlacementJob::class, 1);
            Queue::assertPushed(CleanupWebsitePlacementJob::class, fn (CleanupWebsitePlacementJob $job): bool => $job->afterCommit === true
                && $job->websiteId === $target->id && $job->serverId === $previous->id);
        }
    }

    #[DataProvider('targets')]
    public function test_duplicate_failure_callbacks_preserve_the_first_failure(string $type): void
    {
        $target = $this->target($type);
        if ($target instanceof Website) {
            $this->mock(PreviewDeploymentLifecycle::class)->shouldReceive('websiteFailed')->once()
                ->withArgs(function (Website $website) use ($target): bool {
                    $this->assertGreaterThan(1, DB::transactionLevel());

                    return $website->is($target) && $website->provisioning_status === Website::STATUS_FAILED;
                });
        }
        $url = $this->signedCallbackUrl($target, 'failed');

        $this->post($url, ['message' => 'First failure', 'exit_code' => '2'])->assertNoContent();
        $failed = $target->fresh()->getRawOriginal();
        $this->post($url, ['message' => 'Duplicate failure'])->assertNoContent();
        $this->post($this->signedCallbackUrl($target, 'status'), ['status' => (string) $this->finalStage($target)])->assertNoContent();

        $this->assertSame('First failure (exit code 2)', $target->fresh()->provisioning_error);
        $this->assertSame($failed, $target->fresh()->getRawOriginal());
        if ($target instanceof Server) {
            $this->assertSame(ServerLogSnapshot::STATUS_FAILED, $target->logSnapshots()->sole()->status);
        }
    }

    #[DataProvider('targets')]
    public function test_status_string_normalization_keeps_validation_bounds(string $type): void
    {
        $target = $this->target($type);
        $before = $target->fresh()->getRawOriginal();
        foreach (['-1', '999', '1.5', 'invalid'] as $stage) {
            $this->postJson($this->signedCallbackUrl($target, 'status'), ['status' => $stage])->assertUnprocessable()->assertJsonValidationErrors('status');
        }
        $this->assertSame($before, $target->fresh()->getRawOriginal());
    }

    /** @return iterable<string, array{string}> */
    public static function targets(): iterable
    {
        yield 'server' => ['server'];
        yield 'website' => ['website'];
    }

    public function test_website_completion_rolls_back_when_synchronous_preview_bookkeeping_fails(): void
    {
        $website = $this->target('website');
        $before = $website->fresh()->getRawOriginal();
        $this->mock(PreviewDeploymentLifecycle::class)->shouldReceive('websiteReady')->once()->andThrow(new RuntimeException('Preview persistence failed'));

        $this->post($this->signedCallbackUrl($website, 'status'), ['status' => (string) $this->finalStage($website)])->assertServerError();

        $this->assertSame($before, $website->fresh()->getRawOriginal());
        Queue::assertNothingPushed();
    }

    public function test_server_failure_rolls_back_when_its_snapshot_cannot_be_saved(): void
    {
        $server = $this->target('server');
        $before = $server->fresh()->getRawOriginal();
        Event::listen('eloquent.creating: '.ServerLogSnapshot::class, static function (): never {
            throw new RuntimeException('Snapshot persistence failed');
        });

        $this->post($this->signedCallbackUrl($server, 'failed'), ['message' => 'Remote failure'])->assertServerError();

        $this->assertSame($before, $server->fresh()->getRawOriginal());
        $this->assertDatabaseCount('server_log_snapshots', 0);
    }

    public function test_form_string_build_completion_uses_the_validated_integer_stage(): void
    {
        $website = $this->target('website');
        $user = $website->user;
        $provider = $user->providers()->create(['name' => 'GitHub', 'provider' => Provider::TYPE_GITHUB, 'token' => 'fixture', 'description' => 'Fixture']);
        $repository = $user->repositories()->create([
            'provider_id' => $provider->id, 'website_id' => $website->id, 'name' => 'Application',
            'url' => 'github.com/example/app.git', 'branch' => 'main', 'description' => 'Fixture',
        ]);
        $build = $repository->builds()->create(['status' => Build::STATUS_RUNNING]);
        $this->mock(PreviewDeploymentLifecycle::class)->shouldReceive('buildFinished')->once()->with(Mockery::on(fn (Build $value): bool => $value->is($build)));
        $url = ProvisioningCallbackUrl::buildStatus($build);
        foreach (['-1', '999', '1.5', 'invalid'] as $stage) {
            $this->postJson($url, ['status' => $stage])->assertUnprocessable()->assertJsonValidationErrors('status');
        }
        $stage = app(RepositoryDeploymentPlan::class)->finalStage();

        $this->post($url, ['status' => (string) $stage])->assertNoContent();
        $this->post($url, ['status' => (string) $stage])->assertNoContent();

        $this->assertSame(Build::STATUS_SUCCEEDED, $build->fresh()->status);
        $this->assertSame($stage, $repository->fresh()->setup_stage);
        $this->assertNotNull($build->fresh()->activated_at);
        $this->assertNotNull($build->fresh()->finished_at);
    }

    /** Return an isolated active provisioning target with a current callback token. */
    private function target(string $type): Server|Website
    {
        $user = User::factory()->create();
        $server = $user->servers()->create([
            'name' => 'Callback server', 'provisioning_status' => Server::STATUS_PROVISIONING,
            'provisioning_token' => 'current-attempt', 'setup_stage' => 0,
        ]);
        if ($type === 'server') {
            return $server;
        }

        return $user->websites()->create([
            'server_id' => $server->id, 'name' => 'Callback website', 'description' => 'Fixture',
            'environment' => 'APP_ENV=testing', 'url' => 'callback.example.com',
            'provisioning_status' => Website::STATUS_PROVISIONING, 'provisioning_token' => 'current-attempt',
        ]);
    }

    /** Return a signed status/failure URL without contacting a remote provider. */
    private function signedCallbackUrl(Server|Website $target, string $endpoint): string
    {
        return match (true) {
            $target instanceof Server && $endpoint === 'status' => ProvisioningCallbackUrl::serverStatus($target),
            $target instanceof Server => ProvisioningCallbackUrl::serverFailure($target),
            $endpoint === 'status' => ProvisioningCallbackUrl::websiteStatus($target),
            default => ProvisioningCallbackUrl::websiteFailure($target),
        };
    }

    /** Return the actual runtime-specific completion stage used by the callback. */
    private function finalStage(Server|Website $target): int
    {
        return $target instanceof Server
            ? app(ServerProvisioningPlan::class)->finalStage($target)
            : app(WebsiteProvisioningPlan::class)->finalStage();
    }
}
