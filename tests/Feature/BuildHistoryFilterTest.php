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
        ]);
        $first->builds()->create([
            'status' => Build::STATUS_FAILED,
            'trigger_source' => Build::TRIGGER_WEBHOOK,
            'commit_message' => 'Ship searchable release',
        ]);
        $second->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'trigger_source' => Build::TRIGGER_WEBHOOK,
            'commit_message' => 'Ship searchable release',
        ]);
        $first->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'trigger_source' => Build::TRIGGER_MANUAL,
            'commit_message' => 'Ship searchable release',
        ]);
        $first->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'trigger_source' => Build::TRIGGER_WEBHOOK,
            'commit_message' => 'Unrelated change',
        ]);

        $response = $this->actingAs($owner)->get(route('builds.index', [
            'repository_id' => $first->id,
            'status' => Build::STATUS_SUCCEEDED,
            'trigger' => Build::TRIGGER_WEBHOOK,
            'search' => 'searchable',
        ]));

        $response
            ->assertSuccessful()
            ->assertSee(route('builds.show', $matching))
            ->assertSee('value="'.$first->id.'" selected', false)
            ->assertSee('value="succeeded" selected', false)
            ->assertSee('value="webhook" selected', false)
            ->assertSee('value="searchable"', false);
        $this->assertSame(1, substr_count($response->getContent(), 'aria-label="View build #'));
    }

    public function test_search_matches_repository_name_revision_and_commit_message(): void
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

        $this->actingAs($owner)->get(route('builds.index', ['search' => 'First']))
            ->assertSee(route('builds.show', $byName))
            ->assertDontSee(route('builds.show', $byRevision));
        $this->actingAs($owner)->get(route('builds.index', ['search' => str_repeat('b', 12)]))
            ->assertSee(route('builds.show', $byRevision))
            ->assertDontSee(route('builds.show', $byName));
        $this->actingAs($owner)->get(route('builds.index', ['search' => 'payment timeout']))
            ->assertSee(route('builds.show', $byMessage))
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
            'status' => Build::STATUS_FAILED,
            'trigger' => Build::TRIGGER_MANUAL,
            'search' => 'Release',
        ]));

        $response
            ->assertSuccessful()
            ->assertSee('repository_id='.$repository->id, false)
            ->assertSee('status=failed', false)
            ->assertSee('trigger=manual', false)
            ->assertSee('search=Release', false)
            ->assertSee('page=2', false);

        $this->actingAs($owner)->get(route('builds.index', [
            'status' => 'not-a-status',
            'trigger' => 'not-a-trigger',
            'repository_id' => 'not-an-id',
        ]))
            ->assertSuccessful()
            ->assertDontSee('No builds match these filters')
            ->assertDontSee('not-a-status', false)
            ->assertDontSee('not-a-trigger', false)
            ->assertDontSee('not-an-id', false);
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
