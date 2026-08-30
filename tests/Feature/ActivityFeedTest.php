<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Event;
use App\Models\Repository;
use App\Models\Server;
use App\Models\ServerCommandExecution;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ActivityFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_view_activity(): void
    {
        $this->get(route('activity.index'))->assertRedirect(route('login'));
    }

    public function test_resource_lifecycle_events_are_recorded_without_command_secrets(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        [$server, $website, $repository] = $this->createResources($user, 'Primary');

        $server->update(['provisioning_status' => Server::STATUS_ACTIVE]);
        $website->update(['provisioning_status' => $website::STATUS_ACTIVE]);
        $build = $repository->builds()->create(['status' => Build::STATUS_QUEUED]);
        $build->update(['status' => Build::STATUS_SUCCEEDED]);
        $execution = $server->commandExecutions()->create([
            'user_id' => $user->id,
            'command' => 'echo super-secret-value',
            'status' => ServerCommandExecution::STATUS_QUEUED,
        ]);
        $execution->update(['status' => ServerCommandExecution::STATUS_SUCCEEDED]);

        $this->assertDatabaseHas('events', ['user_id' => $user->id, 'category' => 'server', 'event' => 'Server "Primary Server" is active.']);
        $this->assertDatabaseHas('events', ['user_id' => $user->id, 'category' => 'website', 'event' => 'Website "primary website" is active.']);
        $this->assertDatabaseHas('events', ['user_id' => $user->id, 'category' => 'deployment', 'event' => 'Deployment succeeded.']);
        $this->assertDatabaseHas('events', ['user_id' => $user->id, 'category' => 'command', 'event' => 'Server command succeeded.']);
        $this->assertFalse(Event::query()->where('event', 'like', '%super-secret-value%')->exists());
    }

    public function test_activity_pages_are_owner_scoped_and_escape_resource_names(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $unsafeName = '<script>alert(1)</script>';
        $this->createServer($user, $unsafeName);
        $this->createServer($otherUser, 'Other Account Server');

        foreach ([route('dashboard'), route('activity.index')] as $url) {
            $response = $this->actingAs($user)->get($url);

            $response
                ->assertSuccessful()
                ->assertSee($unsafeName)
                ->assertDontSee($unsafeName, false)
                ->assertDontSee('Other Account Server');
        }
    }

    public function test_activity_history_is_paginated_newest_first(): void
    {
        $user = User::factory()->create();
        $server = $this->createServer($user, 'Timeline');

        foreach (range(0, 29) as $position) {
            $event = $server->events()->make([
                'user_id' => $user->id,
                'category' => 'server',
                'event' => "Timeline activity {$position}",
            ]);
            $event->created_at = now()->addSeconds($position + 1);
            $event->save();
        }

        $this->actingAs($user)
            ->get(route('activity.index'))
            ->assertSuccessful()
            ->assertViewHas('events', fn ($events): bool => $events->count() === 25 && $events->lastPage() === 2)
            ->assertSee('Timeline activity 29')
            ->assertDontSee('Timeline activity 0');
    }

    public function test_events_remain_readable_when_their_subject_is_deleted(): void
    {
        $user = User::factory()->create();
        $server = $this->createServer($user, 'Retired');
        $event = $user->events()->latest()->firstOrFail();
        $oldUrl = route('servers.show', $server);

        $server->deleteQuietly();
        $event->refresh()->unsetRelation('parentable');

        $this->assertNull($event->parentable);
        $this->assertNull($event->url());
        $this->actingAs($user)
            ->get(route('activity.index'))
            ->assertSuccessful()
            ->assertSee('Server "Retired" was created.')
            ->assertDontSee($oldUrl);
    }

    /**
     * @return array{Server, Website, Repository}
     */
    private function createResources(User $user, string $name): array
    {
        $server = $this->createServer($user, "{$name} Server");
        $website = $user->websites()->create([
            'server_id' => $server->id,
            'name' => "{$name} Website",
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'url' => str($name)->slug().'.test',
        ]);
        $repository = $user->repositories()->create([
            'provider_id' => $server->provider_id,
            'website_id' => $website->id,
            'name' => "{$name} Repository",
            'url' => 'github.com/example/project.git',
            'description' => 'Repository',
        ]);

        return [$server, $website, $repository];
    }

    private function createServer(User $user, string $name): Server
    {
        $provider = $user->providers()->create([
            'name' => "{$name} Provider",
            'provider' => 'github',
            'token' => 'secret',
            'description' => 'Provider',
        ]);

        return $user->servers()->create([
            'name' => $name,
            'provider_id' => $provider->id,
            'provisioning_status' => Server::STATUS_QUEUED,
        ]);
    }
}
