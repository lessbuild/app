<?php

namespace Tests\Feature;

use App\Enums\BuildStatus;
use App\Models\Build;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RepositoryBuildRelationsTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('laterBuildStatuses')]
    public function test_latest_successful_build_survives_later_unsuccessful_or_pending_builds(string $laterStatus): void
    {
        $repository = $this->repository(User::factory()->create(), 'Application');
        $repository->builds()->create(['status' => Build::STATUS_SUCCEEDED]);
        $successful = $repository->builds()->create(['status' => Build::STATUS_SUCCEEDED]);
        $latest = $repository->builds()->create(['status' => $laterStatus]);

        $this->assertTrue($repository->latestBuild->is($latest));
        $this->assertNotNull($repository->latestSuccessfulBuild);
        $this->assertTrue($repository->latestSuccessfulBuild->is($successful));
        $this->assertTrue($repository->latestSuccessfulBuild()->sole()->is($successful));
    }

    public function test_build_relations_eager_load_in_constant_queries_and_keep_each_repository_history_separate(): void
    {
        $owner = User::factory()->create();
        $expected = [];
        foreach (range(1, 4) as $index) {
            $repository = $this->repository($owner, "Application {$index}");
            $successful = $repository->builds()->create(['status' => Build::STATUS_SUCCEEDED]);
            $latest = $repository->builds()->create(['status' => Build::STATUS_FAILED]);
            $expected[$repository->id] = [$successful->id, $latest->id];
        }
        $neverSucceeded = $this->repository($owner, 'Never succeeded');
        $neverSucceeded->builds()->create(['status' => Build::STATUS_FAILED]);
        $foreign = $this->repository(User::factory()->create(), 'Foreign application');
        $foreign->builds()->create(['status' => Build::STATUS_SUCCEEDED]);

        DB::enableQueryLog();
        try {
            $repositories = $owner->repositories()->with(['latestBuild', 'latestSuccessfulBuild'])->get()->keyBy('id');
            $this->assertCount(3, DB::getQueryLog());
            $this->assertCount(5, $repositories);
            $this->assertNull($repositories->get($neverSucceeded->id)->latestSuccessfulBuild);
            foreach ($expected as $repositoryId => [$successfulId, $latestId]) {
                $this->assertSame($successfulId, $repositories->get($repositoryId)->latestSuccessfulBuild?->id);
                $this->assertSame($latestId, $repositories->get($repositoryId)->latestBuild?->id);
            }
            $this->assertCount(3, DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_latest_build_scope_accepts_enums_and_mixed_legacy_values_without_matching_historical_statuses(): void
    {
        $owner = User::factory()->create();
        $failed = $this->repository($owner, 'Failed application');
        $failed->builds()->create(['status' => Build::STATUS_SUCCEEDED]);
        $failed->builds()->create(['status' => Build::STATUS_FAILED]);
        $recovered = $this->repository($owner, 'Recovered application');
        $recovered->builds()->create(['status' => Build::STATUS_FAILED]);
        $recovered->builds()->create(['status' => Build::STATUS_SUCCEEDED]);
        $this->repository($owner, 'Never deployed');

        $this->assertSame([$failed->id], $owner->repositories()->latestBuildStatus(BuildStatus::Failed)->pluck('id')->all());
        $this->assertSame(
            [$failed->id, $recovered->id],
            $owner->repositories()->latestBuildStatus([BuildStatus::Failed, Build::STATUS_SUCCEEDED])->orderBy('id')->pluck('id')->all(),
        );
        $this->assertSame(0, $owner->repositories()->latestBuildStatus([])->count());
    }

    /** @return array<string, array{string}> */
    public static function laterBuildStatuses(): array
    {
        return [
            'queued' => [Build::STATUS_QUEUED],
            'awaiting approval' => [Build::STATUS_AWAITING_APPROVAL],
            'rejected' => [Build::STATUS_REJECTED],
            'deploying' => [Build::STATUS_DEPLOYING],
            'running' => [Build::STATUS_RUNNING],
            'timing out' => [Build::STATUS_TIMING_OUT],
            'failed' => [Build::STATUS_FAILED],
            'canceled' => [Build::STATUS_CANCELED],
        ];
    }

    private function repository(User $owner, string $name): Repository
    {
        $provider = $owner->providers()->create([
            'name' => $name,
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'test-token',
            'description' => 'Source control',
        ]);
        $server = $owner->servers()->create(['name' => $name]);
        $website = $owner->websites()->create([
            'server_id' => $server->id,
            'name' => $name,
            'url' => str($name)->slug().'.example.com',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
        ]);

        return $owner->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => $name,
            'url' => 'github.com/example/'.str($name)->slug().'.git',
            'branch' => 'main',
            'description' => 'Repository',
        ]);
    }
}
