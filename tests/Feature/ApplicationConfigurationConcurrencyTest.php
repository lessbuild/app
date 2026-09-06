<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\ConfigurationOperation;
use App\Models\ConfigurationReview;
use App\Models\Environment;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\ApplicationConfigurationBuilds;
use App\Services\ApplicationConfigurationReconciler;
use App\Services\ApplicationConfigurationResults;
use App\Services\ApplicationConfigurationRetries;
use App\Services\ApplicationConfigurationReviews;
use App\Services\DeploymentLauncher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Throwable;

class ApplicationConfigurationConcurrencyTest extends TestCase
{
    private string $database;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Independent-process database races require pcntl.');
        }
        $this->directory = sys_get_temp_dir().'/buildpusher-concurrency-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->database = $this->directory.'/database.sqlite';
        touch($this->database);
        config(['database.default' => 'configuration_concurrency', 'database.connections.configuration_concurrency' => [
            'driver' => 'sqlite', 'database' => $this->database, 'foreign_key_constraints' => true, 'busy_timeout' => 5000,
            'journal_mode' => 'WAL', 'synchronous' => 'NORMAL',
        ]]);
        DB::purge('configuration_concurrency');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        DB::disconnect('configuration_concurrency');
        if (isset($this->directory)) {
            foreach (glob($this->directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function test_overlapping_apply_of_one_review_returns_one_receipt_and_intent(): void
    {
        [$user, $project, $repository, $yaml, $bindings] = $this->fixture();
        $review = app(ApplicationConfigurationReviews::class)->create($project, $user, $yaml, $bindings);
        $apply = fn () => app(ApplicationConfigurationReconciler::class)->apply(ConfigurationReview::findOrFail($review->id), User::findOrFail($user->id))->id;
        $results = $this->overlap($apply, $apply, 'update "projects"');
        $this->assertSame('ok', $results[0]['status']);
        $this->assertSame($results[0], $results[1]);
        $this->assertDatabaseCount('configuration_applications', 1);
        $this->assertDatabaseCount('configuration_operations', 1);
        $this->assertDatabaseCount('environments', 1);
        $this->assertDatabaseCount('builds', 0);
    }

    public function test_overlapping_distinct_reviews_reject_stale_state_then_reuse_the_intent(): void
    {
        [$user, $project, $repository, $yaml, $bindings] = $this->fixture();
        $reviews = app(ApplicationConfigurationReviews::class);
        $first = $reviews->create($project, $user, $yaml, $bindings);
        $second = $reviews->create($project, $user, $yaml, $bindings);
        $results = $this->overlap(
            fn () => app(ApplicationConfigurationReconciler::class)->apply(ConfigurationReview::findOrFail($first->id), User::findOrFail($user->id))->id,
            fn () => app(ApplicationConfigurationReconciler::class)->apply(ConfigurationReview::findOrFail($second->id), User::findOrFail($user->id))->id,
            'update "projects"',
        );
        $this->assertSame('ok', $results[0]['status']);
        $this->assertSame('validation', $results[1]['status']);
        $this->assertDatabaseCount('configuration_applications', 1);
        $receipt = app(ApplicationConfigurationReconciler::class)->apply($reviews->create($project->fresh(), $user->fresh(), $yaml, $bindings), $user);
        $this->assertSame(1, $receipt->relatedOperations()->count());
        $this->assertDatabaseCount('configuration_operations', 1);
        $this->assertDatabaseCount('environments', 1);
    }

    public function test_overlapping_explicit_retries_create_one_replacement_build(): void
    {
        [$user, $project, $repository, $yaml, $bindings] = $this->fixture();
        $receipt = app(ApplicationConfigurationReconciler::class)->apply(app(ApplicationConfigurationReviews::class)->create($project, $user, $yaml, $bindings), $user);
        $operation = $receipt->operations()->firstOrFail();
        app(ApplicationConfigurationBuilds::class)->prepare($operation)->update(['status' => Build::STATUS_FAILED, 'finished_at' => now()]);
        app(ApplicationConfigurationResults::class)->refresh($receipt);
        $retry = fn () => app(ApplicationConfigurationRetries::class)->retry(ConfigurationOperation::findOrFail($operation->id), User::findOrFail($user->id))->id;
        $results = $this->overlap($retry, $retry, 'update "projects"');
        $this->assertSame('ok', $results[0]['status'], json_encode($results));
        $this->assertSame($results[0], $results[1]);
        $this->assertDatabaseCount('configuration_operations', 2);
        $this->assertDatabaseCount('builds', 2);
        $this->assertSame(1, Build::where('status', Build::STATUS_QUEUED)->count());
        $this->assertSame(1, $operation->retry()->count());
    }

    public function test_deployment_committing_during_removal_revalidation_blocks_removal(): void
    {
        [$user, $project, $repository, $yaml, $bindings] = $this->fixture(false);
        app(ApplicationConfigurationReconciler::class)->apply(app(ApplicationConfigurationReviews::class)->create($project, $user, $yaml, $bindings), $user);
        $review = app(ApplicationConfigurationReviews::class)->create($project, $user, "version: 2\nremove:\n  environments: [staging]\n", []);
        $results = $this->overlap(
            fn () => app(DeploymentLauncher::class)->launch(Repository::findOrFail($repository->id), User::findOrFail($user->id))?->id,
            fn () => app(ApplicationConfigurationReconciler::class)->apply(ConfigurationReview::findOrFail($review->id), User::findOrFail($user->id))->id,
            'insert into "builds"',
        );
        $this->assertSame('ok', $results[0]['status']);
        $this->assertSame('validation', $results[1]['status']);
        $this->assertDatabaseCount('environments', 1);
        $this->assertDatabaseCount('builds', 1);
        $this->assertDatabaseCount('configuration_applications', 1);
        $this->assertSame(Environment::firstOrFail()->id, Build::firstOrFail()->environment_id);
        $this->assertNull($review->fresh()->applied_at);
    }

    public function test_manual_child_committing_during_removal_is_never_silently_deleted(): void
    {
        [$user, $project, $repository, $yaml, $bindings] = $this->fixture(false);
        app(ApplicationConfigurationReconciler::class)->apply(app(ApplicationConfigurationReviews::class)->create($project, $user, $yaml, $bindings), $user);
        $environment = $project->environments()->firstOrFail();
        $review = app(ApplicationConfigurationReviews::class)->create($project, $user, "version: 2\nremove:\n  environments: [staging]\n", []);
        $results = $this->overlap(
            fn () => DB::transaction(fn () => Environment::findOrFail($environment->id)->processes()->create(['name' => 'manual', 'type' => 'worker', 'command' => 'work', 'replicas' => 1])->id),
            fn () => app(ApplicationConfigurationReconciler::class)->apply(ConfigurationReview::findOrFail($review->id), User::findOrFail($user->id))->id,
            'insert into "environment_processes"',
        );
        $this->assertSame('ok', $results[0]['status']);
        $this->assertSame('validation', $results[1]['status']);
        $this->assertDatabaseCount('environment_processes', 1);
        $this->assertDatabaseCount('environments', 1);
        $this->assertNull($review->fresh()->applied_at);
    }

    /** Hold the first real transaction open until another process has begun its action. */
    private function overlap(callable $first, callable $second, string $pauseAfterSql): array
    {
        DB::disconnect('configuration_concurrency');
        $children = [];
        foreach ([$first, $second] as $index => $action) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Unable to fork concurrency test.');
            }
            if ($pid === 0) {
                try {
                    DB::purge('configuration_concurrency');
                    if ($index === 0) {
                        $paused = false;
                        DB::listen(function ($query) use ($pauseAfterSql, &$paused): void {
                            if (! $paused && str_starts_with($query->sql, $pauseAfterSql)) {
                                $paused = true;
                                touch($this->directory.'/locked');
                                $this->waitFor($this->directory.'/contender');
                                usleep(150000);
                            }
                        });
                    } else {
                        $this->waitFor($this->directory.'/locked');
                        touch($this->directory.'/contender');
                    }
                    $result = ['status' => 'ok', 'value' => $action()];
                } catch (ValidationException $exception) {
                    $result = ['status' => 'validation', 'fields' => array_keys($exception->errors())];
                } catch (Throwable $exception) {
                    $result = ['status' => 'error', 'class' => get_class($exception), 'message' => $exception->getMessage()];
                }
                file_put_contents($this->directory.'/result-'.$index, json_encode($result, JSON_THROW_ON_ERROR));
                DB::disconnect('configuration_concurrency');
                exit(0);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
        DB::purge('configuration_concurrency');

        return array_map(fn ($index) => json_decode(file_get_contents($this->directory.'/result-'.$index), true, flags: JSON_THROW_ON_ERROR), [0, 1]);
    }

    private function waitFor(string $path): void
    {
        $deadline = microtime(true) + 10;
        while (! file_exists($path)) {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('Concurrent transaction barrier timed out.');
            }
            usleep(1000);
        }
    }

    private function fixture(bool $deploy = true): array
    {
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['name' => 'App', 'slug' => 'app', 'created_by' => $user->id]);
        $provider = $user->providers()->create(['name' => 'GitHub', 'provider' => 'github', 'token' => 'private', 'description' => 'Test']);
        $server = $user->servers()->create(['name' => 'Server', 'provisioning_status' => Server::STATUS_ACTIVE]);
        $website = $user->websites()->create(['server_id' => $server->id, 'name' => 'App', 'url' => 'app.test', 'description' => 'Test', 'environment' => '', 'provisioning_status' => Website::STATUS_ACTIVE]);
        $repository = $user->repositories()->create(['provider_id' => $provider->id, 'website_id' => $website->id, 'name' => 'App', 'url' => 'github.com/example/app.git', 'branch' => 'main', 'description' => 'Test']);
        $yaml = "version: 2\nenvironments:\n  staging:\n    type: staging\n    placement: site\n    runtime:\n      type: php\n".($deploy ? "    deploy:\n      repository: app\n" : '');

        return [$user, $project, $repository, $yaml, ['placements' => ['site' => $website->id], 'repositories' => ['app' => $repository->id]]];
    }
}
