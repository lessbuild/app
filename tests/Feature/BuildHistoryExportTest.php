<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BuildHistoryExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_filtered_history_exports_owned_builds_with_spreadsheet_safe_cells(): void
    {
        [$owner, $repository] = $this->repository('=2+2');
        [, $otherRepository] = $this->repository('Other repository');
        $started = now()->subSeconds(90);
        $matching = $repository->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'trigger_source' => Build::TRIGGER_WEBHOOK,
            'revision' => str_repeat('a', 40),
            'commit_message' => " \t@SUM(1,1)\nShip searchable release",
            'operator_note' => " -1+1\nApproved incident handoff",
            'started_at' => $started,
            'finished_at' => now(),
            'created_at' => '2026-08-20 12:00:00',
        ]);
        $repository->builds()->create([
            'status' => Build::STATUS_FAILED,
            'trigger_source' => Build::TRIGGER_WEBHOOK,
            'commit_message' => 'Ship searchable release',
            'created_at' => '2026-08-20 11:00:00',
        ]);
        $repository->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'trigger_source' => Build::TRIGGER_WEBHOOK,
            'commit_message' => 'Ship searchable release before window',
            'created_at' => '2026-08-19 23:59:59',
        ]);
        $repository->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'trigger_source' => Build::TRIGGER_WEBHOOK,
            'commit_message' => 'Ship searchable release after window',
            'created_at' => '2026-08-21 00:00:00',
        ]);
        $otherRepository->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'trigger_source' => Build::TRIGGER_WEBHOOK,
            'commit_message' => 'Ship searchable release',
            'created_at' => '2026-08-20 10:00:00',
        ]);
        $filters = [
            'repository_id' => $repository->id,
            'status' => Build::STATUS_SUCCEEDED,
            'trigger' => Build::TRIGGER_WEBHOOK,
            'search' => 'searchable',
            'date_from' => '2026-08-20',
            'date_to' => '2026-08-20',
        ];

        $response = $this->actingAs($owner)->get(route('builds.export', $filters));

        $response
            ->assertSuccessful()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString('attachment; filename=lessbuild-builds-', (string) $response->headers->get('content-disposition'));
        $rows = $this->csvRows($response);
        $this->assertSame([
            'Build ID',
            'Repository',
            'Website',
            'Server',
            'Status',
            'Trigger',
            'Revision',
            'Commit message',
            'Operator note',
            'Created at',
            'Started at',
            'Finished at',
            'Duration seconds',
        ], $rows[0]);
        $this->assertCount(2, $rows);
        $this->assertSame((string) $matching->id, $rows[1][0]);
        $this->assertSame("'=2+2", $rows[1][1]);
        $this->assertSame("' \t@SUM(1,1)\nShip searchable release", $rows[1][7]);
        $this->assertSame("' -1+1\nApproved incident handoff", $rows[1][8]);
        $this->assertSame('90', $rows[1][12]);
        $this->actingAs($owner)->get(route('builds.index', $filters))
            ->assertSuccessful()
            ->assertSee(route('builds.export', $filters));
    }

    public function test_foreign_repository_filter_exports_only_the_header(): void
    {
        [$owner, $ownRepository] = $this->repository('Owner repository');
        [, $foreignRepository] = $this->repository('Foreign repository');
        $ownRepository->builds()->create(['status' => Build::STATUS_SUCCEEDED]);
        $foreignRepository->builds()->create(['status' => Build::STATUS_SUCCEEDED]);

        $response = $this->actingAs($owner)->get(route('builds.export', [
            'repository_id' => $foreignRepository->id,
        ]));

        $response->assertSuccessful();
        $this->assertCount(1, $this->csvRows($response));
    }

    public function test_website_filter_exports_builds_across_its_owned_repositories(): void
    {
        [$owner, $repository] = $this->repository('Primary repository');
        $sibling = $owner->repositories()->create([
            'provider_id' => $repository->provider_id,
            'website_id' => $repository->website_id,
            'name' => 'Sibling repository',
            'url' => 'github.com/example/sibling.git',
            'branch' => 'main',
            'description' => 'Sibling source',
        ]);
        $repository->builds()->create(['status' => Build::STATUS_SUCCEEDED]);
        $sibling->builds()->create(['status' => Build::STATUS_FAILED]);
        $otherWebsite = $owner->websites()->create([
            'server_id' => $repository->website->server_id,
            'name' => 'Other website',
            'description' => 'Other website',
            'environment' => 'APP_ENV=production',
            'url' => 'other-website.example.com',
        ]);
        $otherRepository = $owner->repositories()->create([
            'provider_id' => $repository->provider_id,
            'website_id' => $otherWebsite->id,
            'name' => 'Other website repository',
            'url' => 'github.com/example/other-website.git',
            'branch' => 'main',
            'description' => 'Other website source',
        ]);
        $otherRepository->builds()->create(['status' => Build::STATUS_SUCCEEDED]);

        $response = $this->actingAs($owner)->get(route('builds.export', [
            'website_id' => $repository->website_id,
        ]));

        $response->assertSuccessful();
        $rows = $this->csvRows($response);
        $this->assertCount(3, $rows);
        $this->assertEqualsCanonicalizing(
            ['Primary repository', 'Sibling repository'],
            array_column(array_slice($rows, 1), 1),
        );
        $this->assertNotContains('Other website repository', array_column($rows, 1));
    }

    public function test_latest_filter_excludes_failures_superseded_by_a_successful_build(): void
    {
        [$owner, $repository] = $this->repository('Recovered repository');
        $repository->builds()->create(['status' => Build::STATUS_FAILED]);
        $latest = $repository->builds()->create(['status' => Build::STATUS_SUCCEEDED]);

        $failedResponse = $this->actingAs($owner)->get(route('builds.export', [
            'status' => Build::STATUS_FAILED,
            'latest' => 1,
        ]));
        $failedResponse->assertSuccessful();
        $this->assertCount(1, $this->csvRows($failedResponse));

        $latestResponse = $this->actingAs($owner)->get(route('builds.export', ['latest' => 1]));
        $latestResponse->assertSuccessful();
        $rows = $this->csvRows($latestResponse);
        $this->assertCount(2, $rows);
        $this->assertSame((string) $latest->id, $rows[1][0]);
    }

    public function test_export_requires_authentication(): void
    {
        $this->get(route('builds.export'))->assertRedirect(route('login'));
    }

    /** @return array{User, Repository} */
    private function repository(string $name): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'GitHub',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'source-secret',
            'description' => 'Source provider',
        ]);
        $server = $owner->servers()->create(['name' => "{$name} server"]);
        $website = $owner->websites()->create([
            'server_id' => $server->id,
            'name' => "{$name} website",
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'url' => str($name)->slug().'.example.com',
        ]);
        $repository = $owner->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => $name,
            'url' => 'github.com/example/application.git',
            'branch' => 'main',
            'description' => 'Application source',
        ]);

        return [$owner, $repository];
    }

    /** @return list<list<string|null>> */
    private function csvRows(TestResponse $response): array
    {
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $stream = fopen('php://temp', 'w+b');
        $this->assertNotFalse($stream);
        fwrite($stream, substr($content, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }
}
