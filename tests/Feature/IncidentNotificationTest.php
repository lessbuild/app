<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\IncidentNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_acknowledges_only_matching_owner_failures_and_retains_history(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $incidents = app(IncidentNotifier::class);

        $incidents->fail($owner, 'website', 42, 'First outage', 'HTTP 500');
        $incidents->fail($owner, 'website', 42, 'Repeated outage', 'HTTP 503');
        $incidents->fail($owner, 'website', 43, 'Other website outage', 'HTTP 502');
        $incidents->fail($owner, 'server', 42, 'Server outage', 'SSH failed');
        $incidents->fail($other, 'website', 42, 'Foreign outage', 'Private failure');

        $resolved = $incidents->recover(
            $owner,
            'website',
            42,
            'Website recovered',
            'Health checks are passing.',
        );

        $this->assertSame(2, $resolved);
        $matchingFailures = $owner->notifications()
            ->where('data->category', 'website')
            ->where('data->resource_id', 42)
            ->where('data->status', 'failed')
            ->get();
        $this->assertCount(2, $matchingFailures);
        $this->assertTrue($matchingFailures->every(fn ($notification): bool => $notification->read_at !== null));
        $this->assertSame(3, $owner->unreadNotifications()->count());
        $this->assertSame(1, $owner->unreadNotifications()->where('data->status', 'healthy')->count());
        $this->assertSame(1, $owner->unreadNotifications()->where('data->resource_id', 43)->count());
        $this->assertSame(1, $owner->unreadNotifications()->where('data->category', 'server')->count());
        $this->assertSame(1, $other->unreadNotifications()->count());
        $this->assertSame('Foreign outage', $other->unreadNotifications()->sole()->data['title']);

        $notificationCount = $owner->notifications()->count();
        $this->assertSame(0, $incidents->recoverIfOpen(
            $owner,
            'website',
            99,
            'No incident recovered',
            'This must not be sent.',
        ));
        $this->assertSame($notificationCount, $owner->notifications()->count());

        $this->actingAs($owner)->get(route('notifications.index'))
            ->assertSuccessful()
            ->assertSee('First outage')
            ->assertSee('Repeated outage')
            ->assertSee('Website recovered')
            ->assertSee('border-green-300', false)
            ->assertDontSee('Foreign outage');
    }
}
