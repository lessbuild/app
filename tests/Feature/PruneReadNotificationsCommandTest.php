<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\FailureNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PruneReadNotificationsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_prunes_only_notifications_read_before_the_retention_window(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $expired = $this->notification($owner, 'Expired read alert', now()->subDays(91), now()->subDays(100));
        $foreignExpired = $this->notification($other, 'Expired read alert for another account', now()->subDays(120));
        $recentlyReadOldAlert = $this->notification($owner, 'Recently reviewed old alert', now()->subDays(2), now()->subYear());
        $recent = $this->notification($owner, 'Recent read alert', now()->subDays(89));
        $unread = $this->notification($owner, 'Old unread alert', null, now()->subYear());

        $this->assertSame(0, Artisan::call('lessbuild:notifications:prune'));
        $this->assertStringContainsString(
            'Pruned 2 read notification(s) reviewed more than 90 day(s) ago.',
            Artisan::output(),
        );
        $this->assertModelMissing($expired);
        $this->assertModelMissing($foreignExpired);
        $this->assertModelExists($recentlyReadOldAlert);
        $this->assertModelExists($recent);
        $this->assertModelExists($unread);
        $this->assertNull($unread->fresh()->read_at);
    }

    public function test_command_supports_an_explicit_retention_window(): void
    {
        $owner = User::factory()->create();
        $expired = $this->notification($owner, 'Custom expired alert', now()->subDays(31));
        $recent = $this->notification($owner, 'Custom recent alert', now()->subDays(29));

        $this->assertSame(0, Artisan::call('lessbuild:notifications:prune', ['--days' => '30']));
        $this->assertModelMissing($expired);
        $this->assertModelExists($recent);
    }

    public function test_command_rejects_invalid_retention_without_deleting_notifications(): void
    {
        $owner = User::factory()->create();
        $notification = $this->notification($owner, 'Must remain', now()->subYear());

        foreach (['0', '-1', '1.5', 'invalid'] as $days) {
            $this->assertSame(1, Artisan::call('lessbuild:notifications:prune', ['--days' => $days]));
            $this->assertStringContainsString('Retention days must be a positive integer.', Artisan::output());
            $this->assertModelExists($notification);
        }
    }

    private function notification(
        User $user,
        string $title,
        mixed $readAt,
        mixed $createdAt = null,
    ): DatabaseNotification {
        $user->notify(new FailureNotification('server', 1, $title, 'Notification retention test'));
        $notification = $user->notifications()->where('data->title', $title)->sole();
        $createdAt ??= $readAt ?? now()->subYear();
        $notification->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'read_at' => $readAt,
        ])->save();

        return $notification->fresh();
    }
}
