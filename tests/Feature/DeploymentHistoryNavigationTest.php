<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeploymentHistoryNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_detail_shows_duration_and_stable_same_repository_navigation(): void
    {
        [$owner, $repository, $otherRepository] = $this->repositories();
        $previous = $repository->builds()->create([
            'status' => Build::STATUS_FAILED,
            'revision' => str_repeat('a', 40),
            'started_at' => '2026-08-20 08:00:00',
            'finished_at' => '2026-08-20 08:01:05',
            'created_at' => '2026-08-20 08:00:00',
        ]);
        $current = $repository->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'revision' => str_repeat('b', 40),
            'started_at' => '2026-08-20 09:00:00',
            'finished_at' => '2026-08-20 10:02:03',
            'created_at' => '2026-08-20 09:00:00',
        ]);
        $next = $repository->builds()->create([
            'status' => Build::STATUS_CANCELED,
            'revision' => str_repeat('c', 40),
            'started_at' => '2026-08-20 10:00:10',
            'finished_at' => '2026-08-20 10:00:00',
            'created_at' => '2026-08-20 09:00:00',
        ]);
        $foreign = $otherRepository->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'created_at' => '2026-08-20 08:30:00',
        ]);

        $this->assertTrue($current->previousInRepository()->is($previous));
        $this->assertTrue($current->nextInRepository()->is($next));
        $this->assertSame(3723, $current->durationSeconds());
        $this->assertSame('1h 2m 3s', $current->durationLabel());
        $this->assertNull($next->durationSeconds());
        $this->assertNull($next->durationLabel());

        $this->actingAs($owner)->get(route('builds.show', $current))
            ->assertSuccessful()
            ->assertSee('Duration')
            ->assertSee('1h 2m 3s')
            ->assertSee('Previous deployment')
            ->assertSee(route('builds.show', $previous))
            ->assertSee('1m 5s')
            ->assertSee('Next deployment')
            ->assertSee(route('builds.show', $next))
            ->assertSee('Duration not recorded')
            ->assertDontSee(route('builds.show', $foreign));
    }

    public function test_history_edges_and_list_render_honest_duration_states(): void
    {
        [$owner, $repository] = $this->repositories();
        $first = $repository->builds()->create([
            'status' => Build::STATUS_RUNNING,
            'started_at' => '2026-08-20 08:00:00',
            'created_at' => '2026-08-20 08:00:00',
        ]);
        $latest = $repository->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'started_at' => '2026-08-20 09:00:00',
            'finished_at' => '2026-08-20 09:01:00',
            'created_at' => '2026-08-20 09:00:00',
        ]);

        $this->actingAs($owner)->get(route('builds.show', $first))
            ->assertSuccessful()
            ->assertSee('This is the first recorded deployment for this repository.')
            ->assertSee(route('builds.show', $latest));
        $this->get(route('builds.show', $latest))
            ->assertSuccessful()
            ->assertSee(route('builds.show', $first))
            ->assertSee('This is the latest recorded deployment for this repository.');
        $this->get(route('builds.index'))
            ->assertSuccessful()
            ->assertSee('Duration: 1m')
            ->assertSee('Duration: Not recorded');
    }

    /** @return array{User, Repository, Repository} */
    private function repositories(): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'GitHub',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'source-secret',
            'description' => 'Source provider',
        ]);
        $server = $owner->servers()->create([
            'name' => 'Production',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $owner->websites()->create([
            'server_id' => $server->id,
            'name' => 'Application',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'url' => 'app.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);

        return [
            $owner,
            $owner->repositories()->create([
                'provider_id' => $provider->id,
                'website_id' => $website->id,
                'name' => 'Application repository',
                'url' => 'github.com/example/application.git',
                'branch' => 'main',
                'description' => 'Application source',
            ]),
            $owner->repositories()->create([
                'provider_id' => $provider->id,
                'website_id' => $website->id,
                'name' => 'Other repository',
                'url' => 'github.com/example/other.git',
                'branch' => 'main',
                'description' => 'Other source',
            ]),
        ];
    }
}
