<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\ConfigurationApplication;
use App\Models\ConfigurationReview;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Services\ApplicationConfigurationResults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApplicationConfigurationResultsPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_read_queries_stay_constant_as_owned_and_reused_operation_history_grows(): void
    {
        $owner = User::factory()->create();
        $project = $owner->currentOrganization->projects()->create(['name' => 'Application', 'slug' => 'application', 'created_by' => $owner->id]);
        $repository = $this->repository($owner);
        $small = $this->receipt($project, $owner);
        $large = $this->receipt($project, $owner);
        $shared = $this->receipt($project, $owner);
        $this->completedRetry($small, $repository, 'small');
        foreach (range(1, 6) as $index) {
            $this->completedRetry($large, $repository, 'environment-'.$index);
        }
        $shared->referencedOperations()->attach($large->operations()->pluck('id'));

        [$smallStatus, $smallReads] = $this->refreshAndCountReads($small);
        [$largeStatus, $largeReads] = $this->refreshAndCountReads($large);
        [$sharedStatus, $sharedReads] = $this->refreshAndCountReads($shared);

        $this->assertSame('succeeded', $smallStatus);
        $this->assertSame('succeeded', $largeStatus);
        $this->assertSame('succeeded', $sharedStatus);
        $this->assertSame($smallReads, $largeReads, 'Adding operation builds and retry history must not add per-operation reads.');
        $this->assertSame($smallReads, $sharedReads, 'Reused receipt operations must use the same bounded read queries.');
        $this->assertSame(12, $large->operations()->count());
        $this->assertSame(0, $shared->operations()->count());
        $this->assertSame(12, $shared->relatedOperations()->count());
    }

    /** @return array{string, int} Updated receipt status and number of read queries. */
    private function refreshAndCountReads(ConfigurationApplication $application): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $updated = app(ApplicationConfigurationResults::class)->refresh($application);
            $reads = count(array_filter(
                DB::getQueryLog(),
                fn (array $query): bool => str_starts_with(strtolower(ltrim($query['query'])), 'select '),
            ));

            return [$updated->status, $reads];
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    private function completedRetry(ConfigurationApplication $application, Repository $repository, string $slug): void
    {
        $failedBuild = $repository->builds()->create(['status' => Build::STATUS_FAILED, 'finished_at' => now()]);
        $failed = $application->operations()->create([
            'environment_slug' => $slug,
            'kind' => 'deploy',
            'status' => 'failed',
            'build_id' => $failedBuild->id,
            'payload' => [],
            'completed_at' => $failedBuild->finished_at,
        ]);
        $successfulBuild = $repository->builds()->create(['status' => Build::STATUS_SUCCEEDED, 'finished_at' => now()]);
        $application->operations()->create([
            'environment_slug' => $slug,
            'kind' => 'deploy',
            'status' => 'succeeded',
            'build_id' => $successfulBuild->id,
            'retry_of_operation_id' => $failed->id,
            'retry_sequence' => 1,
            'payload' => [],
            'completed_at' => $successfulBuild->finished_at,
        ]);
    }

    private function receipt(Project $project, User $owner): ConfigurationApplication
    {
        $review = ConfigurationReview::query()->create([
            'project_id' => $project->id,
            'requested_by' => $owner->id,
            'document' => 'version: 2',
            'bindings' => [],
            'summary' => [],
            'expires_at' => now()->addMinutes(15),
        ]);

        return ConfigurationApplication::query()->create([
            'configuration_review_id' => $review->id,
            'status' => 'awaiting_dispatch',
            'locally_applied_at' => now(),
        ]);
    }

    private function repository(User $owner): Repository
    {
        $provider = $owner->providers()->create(['name' => 'GitHub', 'provider' => 'github', 'token' => 'test-token', 'description' => 'Source control']);
        $server = $owner->servers()->create(['name' => 'Application']);
        $website = $owner->websites()->create([
            'server_id' => $server->id,
            'name' => 'Application',
            'url' => 'application.example.com',
            'description' => 'Website',
            'environment' => '',
        ]);

        return $owner->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => 'Application',
            'url' => 'github.com/example/application.git',
            'branch' => 'main',
            'description' => 'Repository',
        ]);
    }
}
