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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ActivityFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_view_activity(): void
    {
        $this->get(route('activity.index'))->assertRedirect(route('login'));
        $this->get(route('activity.export'))->assertRedirect(route('login'));
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
        $this->assertDatabaseHas('events', ['user_id' => $user->id, 'category' => 'website', 'event' => 'Website "Primary Website" is active.']);
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

        $response = $this->actingAs($user)
            ->get(route('activity.index', [
                'search' => 'Timeline',
                'category' => 'server',
            ]))
            ->assertSuccessful()
            ->assertViewHas('events', fn ($events): bool => $events->count() === 25 && $events->lastPage() === 2)
            ->assertSee('Timeline activity 29')
            ->assertDontSee('Timeline activity 0');

        $nextPageUrl = $response->viewData('events')->nextPageUrl();
        $this->assertStringContainsString('search=Timeline', $nextPageUrl);
        $this->assertStringContainsString('category=server', $nextPageUrl);
    }

    public function test_activity_history_can_be_filtered_by_message_category_and_date(): void
    {
        $user = User::factory()->create();
        $server = $this->createServer($user, 'Filterable');
        $matching = $this->createEvent($user, $server, 'deployment', 'Needle deployment succeeded', '2026-08-20 12:00:00');
        $this->createEvent($user, $server, 'server', 'Needle server changed', '2026-08-20 13:00:00');
        $this->createEvent($user, $server, 'deployment', 'Needle deployment too early', '2026-08-19 23:59:59');
        $this->createEvent($user, $server, 'deployment', 'Unrelated deployment', '2026-08-20 14:00:00');
        $other = User::factory()->create();
        $otherServer = $this->createServer($other, 'Foreign');
        $this->createEvent($other, $otherServer, 'deployment', 'Needle deployment from another owner', '2026-08-20 12:00:00');
        $filters = [
            'search' => 'Needle',
            'category' => 'deployment',
            'date_from' => '2026-08-20',
            'date_to' => '2026-08-20',
        ];

        $this->actingAs($user)->get(route('activity.index', $filters))
            ->assertSuccessful()
            ->assertViewHas('filters', $filters)
            ->assertViewHas('events', fn ($events): bool => $events->count() === 1 && $events->sole()->is($matching))
            ->assertSee('Needle deployment succeeded')
            ->assertDontSee('Needle server changed')
            ->assertDontSee('Needle deployment too early')
            ->assertDontSee('Needle deployment from another owner')
            ->assertSee(route('activity.export', $filters));
    }

    public function test_invalid_activity_filters_are_normalized_without_affecting_history(): void
    {
        $user = User::factory()->create();
        $this->createServer($user, 'Normalization');

        $this->actingAs($user)->get(route('activity.index', [
            'search' => '   ',
            'category' => 'secrets',
            'date_from' => '2026-02-31',
            'date_to' => '../../etc/passwd',
        ]))
            ->assertSuccessful()
            ->assertViewHas('filters', [
                'search' => null,
                'category' => null,
                'date_from' => null,
                'date_to' => null,
            ])
            ->assertSee('Server &quot;Normalization&quot; was created.', false);
    }

    public function test_filtered_activity_export_is_owner_scoped_and_spreadsheet_safe(): void
    {
        $user = User::factory()->create();
        $server = $this->createServer($user, 'Export');
        $matching = $this->createEvent(
            $user,
            $server,
            'server',
            '=HYPERLINK("https://example.test") Spreadsheet incident',
            '2026-08-20 12:00:00',
        );
        $this->createEvent($user, $server, 'website', 'Spreadsheet incident in another category', '2026-08-20 13:00:00');
        $other = User::factory()->create();
        $otherServer = $this->createServer($other, 'Foreign export');
        $this->createEvent($other, $otherServer, 'server', 'Spreadsheet incident from another owner', '2026-08-20 12:00:00');

        $response = $this->actingAs($user)->get(route('activity.export', [
            'search' => 'Spreadsheet',
            'category' => 'server',
            'date_from' => '2026-08-20',
            'date_to' => '2026-08-20',
        ]));

        $response
            ->assertSuccessful()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString('attachment; filename=lessbuild-activity-', (string) $response->headers->get('content-disposition'));
        $rows = $this->csvRows($response);
        $this->assertSame([
            'Event ID',
            'Category',
            'Activity',
            'Resource type',
            'Resource ID',
            'Recorded at',
        ], $rows[0]);
        $this->assertCount(2, $rows);
        $this->assertSame((string) $matching->id, $rows[1][0]);
        $this->assertSame('server', $rows[1][1]);
        $this->assertSame("'=HYPERLINK(\"https://example.test\") Spreadsheet incident", $rows[1][2]);
        $this->assertSame('Server', $rows[1][3]);
        $this->assertSame((string) $server->id, $rows[1][4]);
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

    private function createEvent(
        User $user,
        Server $server,
        string $category,
        string $message,
        string $createdAt,
    ): Event {
        $event = new Event([
            'user_id' => $user->id,
            'category' => $category,
            'event' => $message,
        ]);
        $event->created_at = $createdAt;
        $event->updated_at = $createdAt;
        $server->events()->save($event);

        return $event;
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
