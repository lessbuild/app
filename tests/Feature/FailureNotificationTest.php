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

    public function test_notification_routes_require_authentication(): void
    {
        $this->get(route('notifications.index'))->assertRedirect(route('login'));
        $this->post(route('notifications.read-all'))->assertRedirect(route('login'));
        $this->post(route('notifications.read', 'missing'))->assertRedirect(route('login'));
    }

    private function destinationFor(mixed $notification): ?string
    {
        return $notification
            ? FailureNotification::destination($notification->data)
            : null;
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
