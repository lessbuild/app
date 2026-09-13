<?php

namespace Tests\Feature;

use App\Data\VerifiedRepositoryWebhook;
use App\Models\PreviewDeployment;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Throwable;

class PreviewDeploymentConcurrencyTest extends TestCase
{
    private string $database;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Independent-process preview races require pcntl.');
        }

        $this->directory = sys_get_temp_dir().'/buildpusher-preview-concurrency-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->database = $this->directory.'/database.sqlite';
        touch($this->database);

        config([
            'database.default' => 'preview_concurrency',
            'database.connections.preview_concurrency' => [
                'driver' => 'sqlite',
                'database' => $this->database,
                'foreign_key_constraints' => true,
                'busy_timeout' => 5000,
                'journal_mode' => 'WAL',
                'synchronous' => 'NORMAL',
            ],
            'billing.enforce_limits' => true,
            'billing.enforce_entitlements' => false,
            'billing.plans.free.limits.websites' => null,
            'billing.plans.free.limits.preview_deployments' => 1,
        ]);
        DB::purge('preview_concurrency');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        DB::disconnect('preview_concurrency');

        if (isset($this->directory)) {
            foreach (glob($this->directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_overlapping_pull_requests_cannot_both_reserve_the_last_preview_slot(): void
    {
        [$user, $source] = $this->fixture();
        $results = $this->overlap($source->id);

        $this->assertSame('provisioning', $results[0]['status'], json_encode($results));
        $this->assertSame('preview_limit_reached', $results[1]['status'], json_encode($results));
        $this->assertSame(1, PreviewDeployment::query()->count());
        $this->assertSame(1, $user->currentOrganization->previews()->whereNull('closed_at')->count());
    }

    /** @return array{User, Repository} */
    private function fixture(): array
    {
        $user = User::factory()->create();
        $provider = $user->providers()->create([
            'name' => 'GitHub',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'token',
            'description' => 'Source',
        ]);
        $server = $user->servers()->create([
            'name' => 'Production',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $user->websites()->create([
            'server_id' => $server->id,
            'name' => 'Storefront',
            'description' => 'Source',
            'environment' => 'APP_ENV=production',
            'database_password' => 'source-password',
            'url' => 'store.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $source = $user->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => 'Storefront source',
            'url' => 'github.com/example/storefront.git',
            'branch' => 'main',
            'description' => 'Source',
        ]);
        $project = $user->currentOrganization->projects()->create([
            'created_by' => $user->id,
            'name' => 'Storefront',
            'slug' => 'storefront',
            'preview_enabled' => true,
            'preview_domain' => 'previews.example.com',
            'preview_ttl_hours' => 72,
        ]);
        $project->environments()->create([
            'name' => 'Production',
            'slug' => 'production',
            'type' => 'production',
            'branch' => 'main',
            'server_id' => $server->id,
            'website_id' => $website->id,
            'is_protected' => true,
        ]);

        return [$user, $source];
    }

    /** @return array<int, array<string, mixed>> */
    private function overlap(int $sourceId): array
    {
        DB::disconnect('preview_concurrency');
        $children = [];

        foreach ([17, 18] as $index => $number) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Unable to fork preview concurrency test.');
            }
            if ($pid === 0) {
                try {
                    DB::purge('preview_concurrency');
                    if ($index === 0) {
                        DB::listen(function ($query): void {
                            if (str_starts_with(strtolower(ltrim($query->sql)), 'update "organizations"')) {
                                touch($this->directory.'/locked');
                                $this->waitFor($this->directory.'/contender');
                            }
                        });
                    } else {
                        $this->waitFor($this->directory.'/locked');
                        touch($this->directory.'/contender');
                    }

                    $status = app('App\\Services\\PreviewDeploymentLifecycle')->handle(
                        Repository::query()->findOrFail($sourceId),
                        new VerifiedRepositoryWebhook(
                            deliveryId: 'preview-'.$number,
                            isPush: false,
                            matchesBranch: true,
                            revision: str_repeat((string) $number, 40),
                            previewAction: 'updated',
                            pullRequestNumber: $number,
                            pullRequestTitle: 'Preview '.$number,
                            sourceBranch: 'feature/'.$number,
                            targetBranch: 'main',
                            isFork: false,
                            targetRepository: 'example/storefront',
                        ),
                    );
                    $result = ['status' => $status];
                } catch (Throwable $exception) {
                    $result = [
                        'status' => 'error',
                        'class' => get_class($exception),
                        'message' => $exception->getMessage(),
                    ];
                }

                file_put_contents($this->directory.'/result-'.$index, json_encode($result, JSON_THROW_ON_ERROR));
                DB::disconnect('preview_concurrency');
                exit(0);
            }
            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
        DB::purge('preview_concurrency');

        return array_map(
            fn (int $index): array => json_decode(
                file_get_contents($this->directory.'/result-'.$index),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
            [0, 1],
        );
    }

    private function waitFor(string $path): void
    {
        $deadline = microtime(true) + 10;
        while (! file_exists($path)) {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('Preview concurrency barrier timed out.');
            }
            usleep(1000);
        }
    }
}
