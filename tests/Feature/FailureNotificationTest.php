<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Notifications\FailureNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\TestCase;

class FailureNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_deployment_creates_an_unread_notification_and_direct_link(): void
    {
        [$owner, , , $repository] = $this->infrastructure('Owner');
        $build = $repository->builds()->create(['status' => Build::STATUS_RUNNING]);

        $build->update([
            'status' => Build::STATUS_FAILED,
            'failure_message' => 'Custom build commands failed',
            'finished_at' => now(),
        ]);

        $notification = $owner->unreadNotifications()->sole();
        $this->assertSame('deployment', $notification->data['category']);
        $this->assertSame($build->id, $notification->data['resource_id']);
        $this->assertSame("Deployment #{$build->id} failed", $notification->data['title']);
        $this->assertSame('Custom build commands failed', $notification->data['message']);

        $this->actingAs($owner)->get(route('notifications.index'))
            ->assertSuccessful()
            ->assertSee('Custom build commands failed')
            ->assertSee('Unread')
            ->assertSee('Unread notifications: 1')
            ->assertSee('View and mark read')
            ->assertSee(route('notifications.read', $notification));

        $this->actingAs($owner)->post(route('notifications.read', $notification))
            ->assertRedirect(route('builds.show', $build));
        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertSame(0, $owner->unreadNotifications()->count());
    }

    public function test_website_and_server_failures_create_notifications_without_duplicate_updates(): void
    {
        [$owner, $server, $website] = $this->infrastructure('Owner');

        $website->update([
            'provisioning_status' => Website::STATUS_FAILED,
            'provisioning_error' => 'Caddy reload failed',
        ]);
        $server->update([
            'provisioning_status' => Server::STATUS_FAILED,
            'provisioning_error' => 'Cloud initialization failed',
        ]);
        $website->update(['provisioning_error' => 'Expanded website error details']);
        $server->update(['provisioning_error' => 'Expanded server error details']);

        $notifications = $owner->notifications()->latest()->get();
        $this->assertCount(2, $notifications);
        $this->assertEqualsCanonicalizing(['website', 'server'], $notifications->pluck('data.category')->all());
        $this->assertEqualsCanonicalizing(
            ['Caddy reload failed', 'Cloud initialization failed'],
            $notifications->pluck('data.message')->all(),
        );
        $this->assertSame(route('websites.show', $website), $this->destinationFor($notifications->firstWhere('data.category', 'website')));
        $this->assertSame(route('servers.show', $server), $this->destinationFor($notifications->firstWhere('data.category', 'server')));
    }

    public function test_successful_and_canceled_states_do_not_create_failure_notifications(): void
    {
        [$owner, , , $repository] = $this->infrastructure('Owner');
        $succeeded = $repository->builds()->create(['status' => Build::STATUS_RUNNING]);
        $canceled = $repository->builds()->create(['status' => Build::STATUS_RUNNING]);

        $succeeded->update(['status' => Build::STATUS_SUCCEEDED]);
        $canceled->update(['status' => Build::STATUS_CANCELED]);

        $this->assertSame(0, $owner->notifications()->count());
    }

    public function test_successful_provisioning_retries_resolve_open_server_and_website_incidents(): void
    {
        [$owner, $server, $website] = $this->infrastructure('Recovery');
        $server->update([
            'provisioning_status' => Server::STATUS_FAILED,
            'provisioning_error' => 'Server setup failed',
        ]);
        $website->update([
            'provisioning_status' => Website::STATUS_FAILED,
            'provisioning_error' => 'Website setup failed',
        ]);
        $failures = $owner->unreadNotifications()->get();
        $this->assertCount(2, $failures);

        $server->update(['provisioning_status' => Server::STATUS_QUEUED]);
        $website->update(['provisioning_status' => Website::STATUS_QUEUED]);
        $server->update(['provisioning_status' => Server::STATUS_PROVISIONING]);
        $website->update(['provisioning_status' => Website::STATUS_PROVISIONING]);
        $this->assertSame(2, $owner->unreadNotifications()->count());

        $server->update(['provisioning_status' => Server::STATUS_ACTIVE]);
        $website->update(['provisioning_status' => Website::STATUS_ACTIVE]);

        $this->assertTrue($failures->every(fn ($notification): bool => $notification->fresh()->read_at !== null));
        $recoveries = $owner->unreadNotifications()->where('data->status', 'healthy')->get();
        $this->assertCount(2, $recoveries);
        $this->assertEqualsCanonicalizing([
            'Server "Recovery server" recovered',
            'Website "Recovery website" recovered',
        ], $recoveries->pluck('data.title')->all());
        $this->assertEqualsCanonicalizing([
            'Server provisioning completed successfully.',
            'Website provisioning completed successfully.',
        ], $recoveries->pluck('data.message')->all());

        $this->actingAs($owner)->get(route('notifications.index', ['state' => 'unread']))
            ->assertSuccessful()
            ->assertSee('Server &quot;Recovery server&quot; recovered', false)
            ->assertSee('Website &quot;Recovery website&quot; recovered', false)
            ->assertSee('border-green-300', false)
            ->assertDontSee('Server setup failed')
            ->assertDontSee('Website setup failed');
    }

    public function test_notifications_and_read_actions_are_isolated_per_user(): void
    {
        [$owner, , $website] = $this->infrastructure('Owner');
        [$other, $otherServer] = $this->infrastructure('Other');
        $website->update([
            'provisioning_status' => Website::STATUS_FAILED,
            'provisioning_error' => 'Owner website failed',
        ]);
        $otherServer->update([
            'provisioning_status' => Server::STATUS_FAILED,
            'provisioning_error' => 'Other server failed',
        ]);
        $ownerNotification = $owner->unreadNotifications()->sole();
        $otherNotification = $other->unreadNotifications()->sole();

        $this->actingAs($owner)->get(route('notifications.index'))
            ->assertSee('Owner website failed')
            ->assertDontSee('Other server failed');
        $this->actingAs($owner)->post(route('notifications.read', $otherNotification))
            ->assertNotFound();
        $this->assertNull($otherNotification->fresh()->read_at);

        $this->actingAs($owner)->post(route('notifications.read-all'))
            ->assertSessionHas('success', 'All notifications marked as read.');
        $this->assertNotNull($ownerNotification->fresh()->read_at);
        $this->assertNull($otherNotification->fresh()->read_at);
    }

    public function test_notification_inbox_filters_title_message_category_state_and_created_dates(): void
    {
        $owner = User::factory()->create();
        $matching = $this->notification(
            $owner,
            'website',
            'Website health failed',
            'Caddy returned an unavailable response',
            createdAt: '2026-08-20 12:00:00',
        );
        $read = $this->notification(
            $owner,
            'website',
            'Older website failure',
            'Caddy returned a previous error',
            read: true,
            createdAt: '2026-08-20 11:00:00',
        );
        $server = $this->notification(
            $owner,
            'server',
            'Server provisioning failed',
            'Caddy package installation failed',
            createdAt: '2026-08-20 10:00:00',
        );
        $beforeRange = $this->notification(
            $owner,
            'website',
            'Historic website failure',
            'Caddy returned an old unavailable response',
            createdAt: '2026-08-19 23:59:59',
        );
        $afterRange = $this->notification(
            $owner,
            'website',
            'Future website failure',
            'Caddy returned a future unavailable response',
            createdAt: '2026-08-21 00:00:00',
        );
        $other = User::factory()->create();
        $this->notification(
            $other,
            'website',
            'Foreign website failed',
            'Caddy failed for another owner',
            createdAt: '2026-08-20 09:00:00',
        );
        $filters = [
            'search' => 'Caddy',
            'category' => 'website',
            'state' => 'unread',
            'date_from' => '2026-08-20',
            'date_to' => '2026-08-20',
        ];

        $this->actingAs($owner)->get(route('notifications.index', $filters))
            ->assertSuccessful()
            ->assertViewHas('filters', $filters)
            ->assertViewHas('notifications', fn ($notifications): bool => $notifications->count() === 1 && $notifications->sole()->id === $matching->id)
            ->assertSee(route('notifications.export', $filters))
            ->assertSee('Caddy returned an unavailable response')
            ->assertDontSee($read->data['message'])
            ->assertDontSee($server->data['message'])
            ->assertDontSee($beforeRange->data['message'])
            ->assertDontSee($afterRange->data['message'])
            ->assertDontSee('Caddy failed for another owner');

        $this->actingAs($owner)->get(route('notifications.index', [
            'search' => '   ',
            'category' => 'credentials',
            'state' => 'deleted',
            'date_from' => '2026-02-31',
            'date_to' => '../../etc/passwd',
        ]))
            ->assertSuccessful()
            ->assertViewHas('filters', [
                'search' => null,
                'category' => null,
                'state' => null,
                'date_from' => null,
                'date_to' => null,
            ])
            ->assertViewHas('notifications', fn ($notifications): bool => $notifications->total() === 5);
    }

    public function test_filtered_notification_export_is_owner_scoped_spreadsheet_safe_and_payload_free(): void
    {
        $owner = User::factory()->create();
        $matching = $this->notification(
            $owner,
            'website',
            '=HYPERLINK("https://example.test") Spreadsheet alert',
            " \t@Spreadsheet message",
            createdAt: '2026-08-20 12:00:00',
        );
        $matching->update([
            'data' => [...$matching->data, 'internal_context' => 'do-not-export-this-payload'],
        ]);
        $this->notification(
            $owner,
            'website',
            'Read Spreadsheet alert',
            'Excluded read alert',
            read: true,
            createdAt: '2026-08-20 11:00:00',
        );
        $this->notification(
            $owner,
            'server',
            'Server Spreadsheet alert',
            'Excluded category alert',
            createdAt: '2026-08-20 10:00:00',
        );
        $this->notification(
            $owner,
            'website',
            'Historic Spreadsheet alert',
            'Excluded date alert',
            createdAt: '2026-08-19 23:59:59',
        );
        $this->notification(
            $owner,
            'website',
            'Future Spreadsheet alert',
            'Excluded future date alert',
            createdAt: '2026-08-21 00:00:00',
        );
        $other = User::factory()->create();
        $this->notification(
            $other,
            'website',
            'Foreign Spreadsheet alert',
            'Excluded foreign alert',
            createdAt: '2026-08-20 09:00:00',
        );
        $filters = [
            'search' => 'Spreadsheet',
            'category' => 'website',
            'state' => 'unread',
            'date_from' => '2026-08-20',
            'date_to' => '2026-08-20',
        ];

        $response = $this->actingAs($owner)->get(route('notifications.export', $filters));

        $response
            ->assertSuccessful()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString('attachment; filename=lessbuild-notifications-', (string) $response->headers->get('content-disposition'));
        $content = $response->streamedContent();
        $this->assertStringNotContainsString('do-not-export-this-payload', $content);
        $this->assertStringNotContainsString('internal_context', $content);
        $this->assertStringNotContainsString('Excluded read alert', $content);
        $this->assertStringNotContainsString('Excluded category alert', $content);
        $this->assertStringNotContainsString('Excluded date alert', $content);
        $this->assertStringNotContainsString('Excluded future date alert', $content);
        $this->assertStringNotContainsString('Excluded foreign alert', $content);
        $rows = $this->csvRows($content);
        $this->assertSame([
            'Notification ID',
            'Category',
            'Title',
            'Message',
            'Status',
            'State',
            'Resource ID',
            'Created at',
            'Read at',
        ], $rows[0]);
        $this->assertCount(2, $rows);
        $this->assertSame($matching->id, $rows[1][0]);
        $this->assertSame('website', $rows[1][1]);
        $this->assertSame("'=HYPERLINK(\"https://example.test\") Spreadsheet alert", $rows[1][2]);
        $this->assertSame("' \t@Spreadsheet message", $rows[1][3]);
        $this->assertSame('failed', $rows[1][4]);
        $this->assertSame('unread', $rows[1][5]);
        $this->assertSame('1', $rows[1][6]);
        $this->assertSame('', $rows[1][8]);
    }

    public function test_filtered_notification_pagination_preserves_query_parameters(): void
    {
        $owner = User::factory()->create();
        foreach (range(1, 26) as $position) {
            $this->notification(
                $owner,
                'deployment',
                "Batch deployment {$position} failed",
                'Searchable release failure',
            );
        }

        $today = now()->toDateString();
        $response = $this->actingAs($owner)->get(route('notifications.index', [
            'search' => 'Searchable',
            'category' => 'deployment',
            'state' => 'unread',
            'date_from' => $today,
            'date_to' => $today,
        ]))->assertSuccessful()
            ->assertViewHas('notifications', fn ($notifications): bool => $notifications->count() === 25 && $notifications->lastPage() === 2);

        $nextPageUrl = $response->viewData('notifications')->nextPageUrl();
        $this->assertStringContainsString('search=Searchable', $nextPageUrl);
        $this->assertStringContainsString('category=deployment', $nextPageUrl);
        $this->assertStringContainsString('state=unread', $nextPageUrl);
        $this->assertStringContainsString("date_from={$today}", $nextPageUrl);
        $this->assertStringContainsString("date_to={$today}", $nextPageUrl);
    }

    public function test_read_notifications_can_be_reopened_and_bulk_cleanup_keeps_unread_and_foreign_items(): void
    {
        $owner = User::factory()->create();
        $reopened = $this->notification($owner, 'server', 'Reopen me', 'Review this again', read: true);
        $deletable = $this->notification($owner, 'website', 'Delete me', 'Already reviewed', read: true);
        $unread = $this->notification($owner, 'deployment', 'Keep unread', 'Still needs attention');
        $other = User::factory()->create();
        $foreign = $this->notification($other, 'server', 'Foreign read', 'Another owner reviewed this', read: true);

        $this->actingAs($owner)->post(route('notifications.unread', $reopened))
            ->assertSessionHas('success', 'Notification marked as unread.');
        $this->assertNull($reopened->fresh()->read_at);

        $this->post(route('notifications.unread', $foreign))->assertNotFound();
        $this->assertNotNull($foreign->fresh()->read_at);

        $this->post(route('notifications.clear-read'))
            ->assertSessionHas('success', '1 read notification deleted.');

        $this->assertDatabaseMissing('notifications', ['id' => $deletable->id]);
        $this->assertDatabaseHas('notifications', ['id' => $reopened->id, 'read_at' => null]);
        $this->assertDatabaseHas('notifications', ['id' => $unread->id, 'read_at' => null]);
        $this->assertDatabaseHas('notifications', ['id' => $foreign->id]);
    }

    public function test_notifications_can_be_deleted_individually_without_leaving_the_filtered_inbox(): void
    {
        $owner = User::factory()->create();
        $deleted = $this->notification($owner, 'website', 'Delete one', 'Dismiss this specific alert');
        $preserved = $this->notification($owner, 'website', 'Keep one', 'Keep this specific alert', read: true);
        $other = User::factory()->create();
        $foreign = $this->notification($other, 'website', 'Foreign alert', 'Another account owns this alert');
        $filteredInbox = route('notifications.index', [
            'search' => 'specific',
            'category' => 'website',
            'state' => 'unread',
        ]);

        $this->actingAs($owner)->get($filteredInbox)
            ->assertSuccessful()
            ->assertSee(route('notifications.destroy', $deleted));

        $this->from($filteredInbox)
            ->delete(route('notifications.destroy', $deleted))
            ->assertRedirect($filteredInbox)
            ->assertSessionHas('success', 'Notification deleted.');

        $this->assertModelMissing($deleted);
        $this->assertModelExists($preserved);
        $this->delete(route('notifications.destroy', $foreign))->assertNotFound();
        $this->assertModelExists($foreign);
    }

    public function test_notification_routes_require_authentication(): void
    {
        $this->get(route('notifications.index'))->assertRedirect(route('login'));
        $this->get(route('notifications.export'))->assertRedirect(route('login'));
        $this->post(route('notifications.read-all'))->assertRedirect(route('login'));
        $this->post(route('notifications.clear-read'))->assertRedirect(route('login'));
        $this->post(route('notifications.read', 'missing'))->assertRedirect(route('login'));
        $this->post(route('notifications.unread', 'missing'))->assertRedirect(route('login'));
        $this->delete(route('notifications.destroy', 'missing'))->assertRedirect(route('login'));
    }

    private function destinationFor(mixed $notification): ?string
    {
        return $notification
            ? FailureNotification::destination($notification->data)
            : null;
    }

    private function notification(
        User $user,
        string $category,
        string $title,
        string $message,
        bool $read = false,
        mixed $createdAt = null,
    ): DatabaseNotification {
        $user->notify(new FailureNotification($category, 1, $title, $message));
        $notification = $user->notifications()
            ->where('data->title', $title)
            ->sole();

        if ($read) {
            $notification->markAsRead();
        }

        if ($createdAt !== null) {
            $notification->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->save();
        }

        return $notification->fresh();
    }

    /** @return list<list<string|null>> */
    private function csvRows(string $content): array
    {
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

    /** @return array{User, Server, Website, Repository} */
    private function infrastructure(string $prefix): array
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
        $repository = $owner->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => "{$prefix} repository",
            'url' => 'github.com/example/application.git',
            'branch' => 'main',
            'description' => 'Application source',
        ]);

        return [$owner, $server, $website, $repository];
    }
}
