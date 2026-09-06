<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BuildHistoryFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_combine_repository_status_trigger_and_search_filters(): void
    {
        [$owner, $first, $second] = $this->repositories('Owner');
        $matching = $first->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'trigger_source' => Build::TRIGGER_WEBHOOK,
            'revision' => str_repeat('a', 40),
            'commit_message' => 'Ship searchable release',
            'finished_at' => now(),
            'created_at' => '2026-08-20 12:00:00',
        ]);
        $first->builds()->create([
            'status' => Build::STATUS_FAILED,
            'trigger_source' => Build::TRIGGER_WEBHOOK,
            'commit_message' => 'Ship searchable release',
            'created_at' => '2026-08-20 11:00:00',
        ]);
        $second->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'trigger_source' => Build::TRIGGER_WEBHOOK,
            'commit_message' => 'Ship searchable release',
            'created_at' => '2026-08-20 10:00:00',
        ]);
        $first->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'trigger_source' => Build::TRIGGER_MANUAL,
            'commit_message' => 'Ship searchable release',
            'created_at' => '2026-08-20 09:00:00',
        ]);
        $first->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'trigger_source' => Build::TRIGGER_WEBHOOK,
            'commit_message' => 'Unrelated change',
            'created_at' => '2026-08-20 08:00:00',
        ]);
        $beforeRange = $first->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'trigger_source' => Build::TRIGGER_WEBHOOK,
            'commit_message' => 'Ship searchable release before window',
            'created_at' => '2026-08-19 23:59:59',
        ]);
        $afterRange = $first->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'trigger_source' => Build::TRIGGER_WEBHOOK,
            'commit_message' => 'Ship searchable release after window',
            'created_at' => '2026-08-21 00:00:00',
        ]);

        $response = $this->actingAs($owner)->get(route('builds.index', [
            'repository_id' => $first->id,
            'status' => Build::STATUS_SUCCEEDED,
            'trigger' => Build::TRIGGER_WEBHOOK,
            'search' => 'searchable',
            'date_from' => '2026-08-20',
            'date_to' => '2026-08-20',
        ]));

        $response
            ->assertSuccessful()
            ->assertSee(route('builds.show', $matching))
            ->assertSee('value="'.$first->id.'" selected', false)
            ->assertSee('value="succeeded" selected', false)
            ->assertSee('value="webhook" selected', false)
            ->assertSee('value="searchable"', false)
            ->assertSee('value="2026-08-20"', false)
            ->assertDontSee(route('builds.show', $beforeRange))
            ->assertDontSee(route('builds.show', $afterRange));
        $this->assertSame(1, substr_count($response->getContent(), 'aria-label="View build #'));
    }

    public function test_search_matches_repository_name_revision_commit_message_and_operator_note(): void
    {
        [$owner, $first, $second] = $this->repositories('Owner');
        $byName = $first->builds()->create(['status' => Build::STATUS_SUCCEEDED]);
        $byRevision = $second->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'revision' => str_repeat('b', 40),
        ]);
        $byMessage = $second->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'commit_message' => 'Correct a payment timeout',
        ]);
        $byNote = $second->builds()->create([
            'status' => Build::STATUS_FAILED,
            'operator_note' => 'Investigate incident INC-2048 before retrying',
        ]);

        $this->actingAs($owner)->get(route('builds.index', ['search' => 'First']))
            ->assertSee(route('builds.show', $byName))
            ->assertDontSee(route('builds.show', $byRevision));
        $this->actingAs($owner)->get(route('builds.index', ['search' => str_repeat('b', 12)]))
            ->assertSee(route('builds.show', $byRevision))
            ->assertDontSee(route('builds.show', $byName));
        $this->actingAs($owner)->get(route('builds.index', ['search' => 'payment timeout']))
            ->assertSee(route('builds.show', $byMessage))
            ->assertDontSee(route('builds.show', $byName));
        $this->actingAs($owner)->get(route('builds.index', ['search' => 'INC-2048']))
            ->assertSee(route('builds.show', $byNote))
            ->assertSee('Note: Investigate incident INC-2048 before retrying')
            ->assertDontSee(route('builds.show', $byName));
    }

    public function test_foreign_repository_filter_never_exposes_another_users_builds(): void
    {
        [$owner, $ownRepository] = $this->repositories('Owner');
        [$intruder, $foreignRepository] = $this->repositories('Foreign');
        $ownBuild = $ownRepository->builds()->create(['status' => Build::STATUS_SUCCEEDED]);
        $foreignBuild = $foreignRepository->builds()->create(['status' => Build::STATUS_SUCCEEDED]);

        $this->actingAs($owner)->get(route('builds.index', ['repository_id' => $foreignRepository->id]))
            ->assertSuccessful()
            ->assertSee('No builds match these filters')
            ->assertDontSee(route('builds.show', $ownBuild))
            ->assertDontSee(route('builds.show', $foreignBuild))
            ->assertDontSee('Foreign First repository');

        $this->actingAs($intruder)->get(route('builds.index'))
            ->assertSee(route('builds.show', $foreignBuild))
            ->assertDontSee(route('builds.show', $ownBuild));
    }

    public function test_website_filter_combines_owned_repository_history_and_metrics(): void
    {
        [$owner, $first, $second] = $this->repositories('Owner');
        $firstBuild = $first->builds()->create(['status' => Build::STATUS_SUCCEEDED]);
        $secondBuild = $second->builds()->create(['status' => Build::STATUS_FAILED]);
        $otherWebsite = $owner->websites()->create([
            'server_id' => $first->website->server_id,
            'name' => 'Owner other website',
            'description' => 'Other website',
            'environment' => 'APP_ENV=production',
            'url' => 'owner-other.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $otherRepository = $owner->repositories()->create([
            'provider_id' => $first->provider_id,
            'website_id' => $otherWebsite->id,
            'name' => 'Owner other repository',
            'url' => 'github.com/example/other.git',
            'branch' => 'main',
            'description' => 'Other source',
        ]);
        $otherBuild = $otherRepository->builds()->create(['status' => Build::STATUS_SUCCEEDED]);
        [, $foreignRepository] = $this->repositories('Foreign');
        $foreignBuild = $foreignRepository->builds()->create(['status' => Build::STATUS_SUCCEEDED]);

        $filters = ['website_id' => $first->website_id];
        $this->actingAs($owner)->get(route('builds.index', $filters))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 2,
                'active' => 0,
                'succeeded' => 1,
                'failed' => 1,
                'success_rate' => 50,
                'latest_at' => $secondBuild->created_at,
            ])
            ->assertSee('value="'.$first->website_id.'" selected', false)
            ->assertSee(route('builds.export', $filters))
            ->assertSee(route('builds.show', $firstBuild))
            ->assertSee(route('builds.show', $secondBuild))
            ->assertDontSee(route('builds.show', $otherBuild))
            ->assertDontSee(route('builds.show', $foreignBuild));

        $this->actingAs($owner)->get(route('builds.index', [
            'website_id' => $foreignRepository->website_id,
        ]))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 0,
                'active' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'success_rate' => null,
                'latest_at' => null,
            ])
            ->assertSee('No builds match these filters')
            ->assertDontSee('Foreign website');
    }

    public function test_server_filter_combines_history_across_owned_websites_and_repositories(): void
    {
        [$owner, $first, $second] = $this->repositories('Owner');
        $server = $first->website->server;
        $server->update(['display_name' => 'Owner fleet server']);
        $firstBuild = $first->builds()->create(['status' => Build::STATUS_SUCCEEDED]);
        $secondBuild = $second->builds()->create(['status' => Build::STATUS_FAILED]);
        $siblingWebsite = $owner->websites()->create([
            'server_id' => $server->id,
            'name' => 'Owner sibling website',
            'description' => 'Sibling website',
            'environment' => 'APP_ENV=production',
            'url' => 'owner-sibling.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $siblingRepository = $owner->repositories()->create([
            'provider_id' => $first->provider_id,
            'website_id' => $siblingWebsite->id,
            'name' => 'Owner sibling repository',
            'url' => 'github.com/example/sibling.git',
            'branch' => 'main',
            'description' => 'Sibling source',
        ]);
        $siblingBuild = $siblingRepository->builds()->create(['status' => Build::STATUS_SUCCEEDED]);
        $otherServer = $owner->servers()->create([
            'name' => 'Owner other server',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $otherWebsite = $owner->websites()->create([
            'server_id' => $otherServer->id,
            'name' => 'Owner isolated website',
            'description' => 'Isolated website',
            'environment' => 'APP_ENV=production',
            'url' => 'owner-isolated.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $otherRepository = $owner->repositories()->create([
            'provider_id' => $first->provider_id,
            'website_id' => $otherWebsite->id,
            'name' => 'Owner isolated repository',
            'url' => 'github.com/example/isolated.git',
            'branch' => 'main',
            'description' => 'Isolated source',
        ]);
        $otherBuild = $otherRepository->builds()->create(['status' => Build::STATUS_SUCCEEDED]);
        [, $foreignRepository] = $this->repositories('Foreign');
        $foreignBuild = $foreignRepository->builds()->create(['status' => Build::STATUS_SUCCEEDED]);

        $filters = ['server_id' => $server->id];
        $this->actingAs($owner)->get(route('builds.index', $filters))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 3,
                'active' => 0,
                'succeeded' => 2,
                'failed' => 1,
                'success_rate' => 67,
                'latest_at' => $siblingBuild->created_at,
            ])
            ->assertSee('value="'.$server->id.'" selected', false)
            ->assertSee('Owner fleet server')
            ->assertSee(route('builds.export', $filters))
            ->assertSee(route('builds.show', $firstBuild))
            ->assertSee(route('builds.show', $secondBuild))
            ->assertSee(route('builds.show', $siblingBuild))
            ->assertDontSee(route('builds.show', $otherBuild))
            ->assertDontSee(route('builds.show', $foreignBuild));

        $this->actingAs($owner)->get(route('builds.index', [
            'server_id' => $foreignRepository->website->server_id,
        ]))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 0,
                'active' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'success_rate' => null,
                'latest_at' => null,
            ])
            ->assertSee('No builds match these filters')
            ->assertDontSee('Foreign server');
    }

    public function test_source_provider_filter_combines_owned_repository_history_and_metrics(): void
    {
        [$owner, $first, $second] = $this->repositories('Owner');
        $firstBuild = $first->builds()->create(['status' => Build::STATUS_SUCCEEDED]);
        $secondBuild = $second->builds()->create(['status' => Build::STATUS_FAILED]);
        $otherProvider = $owner->providers()->create([
            'name' => 'Owner GitLab',
            'provider' => Provider::TYPE_GITLAB,
            'token' => 'other-source-secret',
            'description' => 'Other source provider',
        ]);
        $otherRepository = $owner->repositories()->create([
            'provider_id' => $otherProvider->id,
            'website_id' => $first->website_id,
            'name' => 'Owner GitLab repository',
            'url' => 'gitlab.com/example/other.git',
            'branch' => 'main',
            'description' => 'Other provider source',
        ]);
        $otherBuild = $otherRepository->builds()->create(['status' => Build::STATUS_SUCCEEDED]);
        [, $foreignRepository] = $this->repositories('Foreign');
        $foreignBuild = $foreignRepository->builds()->create(['status' => Build::STATUS_SUCCEEDED]);

        $filters = ['provider_id' => $first->provider_id];
        $this->actingAs($owner)->get(route('builds.index', $filters))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 2,
                'active' => 0,
                'succeeded' => 1,
                'failed' => 1,
                'success_rate' => 50,
                'latest_at' => $secondBuild->created_at,
            ])
            ->assertSee('value="'.$first->provider_id.'" selected', false)
            ->assertSee('Owner GitHub')
            ->assertSee(route('builds.export', $filters))
            ->assertSee(route('builds.show', $firstBuild))
            ->assertSee(route('builds.show', $secondBuild))
            ->assertDontSee(route('builds.show', $otherBuild))
            ->assertDontSee(route('builds.show', $foreignBuild));

        $this->actingAs($owner)->get(route('builds.index', [
            'provider_id' => $foreignRepository->provider_id,
        ]))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 0,
                'active' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'success_rate' => null,
                'latest_at' => null,
            ])
            ->assertSee('No builds match these filters')
            ->assertDontSee('Foreign GitHub');
    }

    public function test_latest_filter_returns_only_each_owned_repository_current_build(): void
    {
        [$owner, $first, $second] = $this->repositories('Owner');
        [, $foreign] = $this->repositories('Foreign');
        $obsoleteFailure = $first->builds()->create(['status' => Build::STATUS_FAILED]);
        $currentSuccess = $first->builds()->create(['status' => Build::STATUS_SUCCEEDED]);
        $currentFailure = $second->builds()->create(['status' => Build::STATUS_FAILED]);
        $foreignFailure = $foreign->builds()->create(['status' => Build::STATUS_FAILED]);

        $this->actingAs($owner)->get(route('builds.index', [
            'status' => Build::STATUS_FAILED,
            'latest' => 1,
        ]))
            ->assertSuccessful()
            ->assertSee(route('builds.show', $currentFailure))
            ->assertSee(route('builds.export', [
                'status' => Build::STATUS_FAILED,
                'latest' => 1,
            ]))
            ->assertSee('name="latest" value="1" checked', false)
            ->assertDontSee(route('builds.show', $obsoleteFailure))
            ->assertDontSee(route('builds.show', $currentSuccess))
            ->assertDontSee(route('builds.show', $foreignFailure));
    }

    public function test_filter_query_is_preserved_across_pagination_and_invalid_values_are_discarded(): void
    {
        [$owner, $repository] = $this->repositories('Owner');
        foreach (range(1, 16) as $index) {
            $repository->builds()->create([
                'status' => Build::STATUS_FAILED,
                'trigger_source' => Build::TRIGGER_MANUAL,
                'commit_message' => "Release {$index}",
                'created_at' => now()->addSeconds($index),
            ]);
        }

        $response = $this->actingAs($owner)->get(route('builds.index', [
            'repository_id' => $repository->id,
            'website_id' => $repository->website_id,
            'server_id' => $repository->website->server_id,
            'provider_id' => $repository->provider_id,
            'status' => Build::STATUS_FAILED,
            'trigger' => Build::TRIGGER_MANUAL,
            'search' => 'Release',
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ]));

        $response
            ->assertSuccessful()
            ->assertSee('repository_id='.$repository->id, false)
            ->assertSee('website_id='.$repository->website_id, false)
            ->assertSee('server_id='.$repository->website->server_id, false)
            ->assertSee('provider_id='.$repository->provider_id, false)
            ->assertSee('status=failed', false)
            ->assertSee('trigger=manual', false)
            ->assertSee('search=Release', false)
            ->assertSee('date_from='.now()->toDateString(), false)
            ->assertSee('date_to='.now()->toDateString(), false)
            ->assertSee('page=2', false);

        $this->actingAs($owner)->get(route('builds.index', [
            'status' => 'not-a-status',
            'trigger' => 'not-a-trigger',
            'repository_id' => 'not-an-id',
            'website_id' => '-5',
            'server_id' => '0',
            'provider_id' => 'not-a-provider-id',
            'latest' => 'sometimes',
            'date_from' => '2026-02-31',
            'date_to' => '../../etc/passwd',
        ]))
            ->assertSuccessful()
            ->assertViewHas('filters', [
                'repository_id' => null,
                'website_id' => null,
                'server_id' => null,
                'provider_id' => null,
                'status' => null,
                'trigger' => null,
                'search' => null,
                'active' => null,
                'latest' => null,
                'date_from' => null,
                'date_to' => null,
            ])
            ->assertDontSee('No builds match these filters')
            ->assertDontSee('not-a-status', false)
            ->assertDontSee('not-a-trigger', false)
            ->assertDontSee('not-an-id', false)
            ->assertDontSee('not-a-provider-id', false)
            ->assertDontSee('name="latest" value="1" checked', false);
    }

    public function test_active_filter_drills_into_in_progress_deployments_and_export(): void
    {
        [$owner, $repository] = $this->repositories('Owner');
        $queued = $repository->builds()->create([
            'status' => Build::STATUS_QUEUED,
            'commit_message' => 'Queued active drilldown',
        ]);
        $running = $repository->builds()->create([
            'status' => Build::STATUS_RUNNING,
            'commit_message' => 'Running active drilldown',
        ]);
        $completed = $repository->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'commit_message' => 'Completed deployment excluded',
        ]);

        $filters = ['active' => 1];
        $this->actingAs($owner)->get(route('builds.index', $filters))
            ->assertSuccessful()
            ->assertViewHas('filters', fn (array $filters): bool => $filters['active'] === '1')
            ->assertViewHas('builds', fn ($builds): bool => $builds->pluck('id')->sort()->values()->all() === collect([$queued->id, $running->id])->sort()->values()->all())
            ->assertSee('name="active" value="1" checked', false)
            ->assertSee(route('builds.export', $filters))
            ->assertDontSee(route('builds.show', $completed));

        $csv = $this->actingAs($owner)->get(route('builds.export', $filters))
            ->assertSuccessful()
            ->streamedContent();
        $this->assertStringContainsString('Queued active drilldown', $csv);
        $this->assertStringContainsString('Running active drilldown', $csv);
        $this->assertStringNotContainsString('Completed deployment excluded', $csv);
    }

    public function test_latest_filter_is_preserved_across_repository_pagination(): void
    {
        [$owner, $first] = $this->repositories('Owner');
        $repositories = collect([$first]);
        foreach (range(2, 16) as $index) {
            $repositories->push($owner->repositories()->create([
                'provider_id' => $first->provider_id,
                'website_id' => $first->website_id,
                'name' => "Owner Repository {$index}",
                'url' => "github.com/example/repository-{$index}.git",
                'branch' => 'main',
                'description' => 'Source',
            ]));
        }
        foreach ($repositories as $repository) {
            $repository->builds()->create(['status' => Build::STATUS_FAILED]);
        }

        $this->actingAs($owner)->get(route('builds.index', [
            'status' => Build::STATUS_FAILED,
            'latest' => 1,
        ]))
            ->assertSuccessful()
            ->assertSee('status=failed', false)
            ->assertSee('latest=1', false)
            ->assertSee('page=2', false);

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertSuccessful()
            ->assertSee(route('builds.index', [
                'status' => Build::STATUS_FAILED,
                'latest' => 1,
            ]));
    }

    public function test_history_remains_readable_while_website_deletion_is_pending(): void
    {
        Queue::fake();
        [$owner, $repository] = $this->repositories('Owner');
        $build = $repository->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'finished_at' => now(),
        ]);
        $this->assertTrue($repository->isDeploymentReady());
        $repository->website->delete();

        $this->assertTrue($repository->fresh()->website->trashed());
        $this->assertFalse($repository->fresh()->isDeploymentReady());
        $this->actingAs($owner)->get(route('builds.index'))
            ->assertSuccessful()
            ->assertSee(route('builds.show', $build))
            ->assertSee('Owner server');
        $this->actingAs($owner)->get(route('builds.show', $build))
            ->assertSuccessful()
            ->assertSee('Owner First repository');
    }

    /** @return array{User, Repository, Repository} */
    private function repositories(string $prefix): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => "{$prefix} GitHub",
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'source-secret',
            'description' => 'Source provider',
        ]);
        $server = $owner->servers()->create([
            'name' => "{$prefix} server",
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $owner->websites()->create([
            'server_id' => $server->id,
            'name' => "{$prefix} website",
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'url' => strtolower($prefix).'.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);

        return [
            $owner,
            $owner->repositories()->create([
                'provider_id' => $provider->id,
                'website_id' => $website->id,
                'name' => "{$prefix} First repository",
                'url' => 'github.com/example/first.git',
                'branch' => 'main',
                'description' => 'First source',
            ]),
            $owner->repositories()->create([
                'provider_id' => $provider->id,
                'website_id' => $website->id,
                'name' => "{$prefix} Second repository",
                'url' => 'github.com/example/second.git',
                'branch' => 'main',
                'description' => 'Second source',
            ]),
        ];
    }
}
