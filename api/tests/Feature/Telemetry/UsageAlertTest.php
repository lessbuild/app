<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Project;
use App\Models\TelemetryUsageEntry;
use App\Models\UsageAlertDelivery;
use App\Models\User;
use App\Notifications\UsageAlertNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class UsageAlertTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'));
        $this->account = Project::factory()->withServices(['monitoring'])->create()->account;
        $this->owner = $this->ownerOf($this->account);
    }

    public function test_owners_are_emailed_once_per_threshold_each_month(): void
    {
        Notification::fake();
        $member = User::factory()->create();
        $this->addMember($this->account, $member, AccountRole::Admin);
        $this->usage(400_000);

        $this->command('usage:send-alerts')->expectsOutput('Usage alerts: 1 sent, 0 skipped, 0 failed.')->assertSuccessful();
        Notification::assertSentTo($this->owner, UsageAlertNotification::class, fn (UsageAlertNotification $notification): bool => $notification->toMail($this->owner)->subject === $this->account->name.' has used 80% of this month’s Monitoring events');
        Notification::assertNotSentTo($member, UsageAlertNotification::class);
        $this->command('usage:send-alerts')->expectsOutput('Usage alerts: 0 sent, 1 skipped, 0 failed.')->assertSuccessful();

        $this->usage(100_000);
        $this->command('usage:send-alerts')->expectsOutput('Usage alerts: 1 sent, 0 skipped, 0 failed.')->assertSuccessful();
        $this->assertSame([80, 100], UsageAlertDelivery::query()->orderBy('threshold')->pluck('threshold')->all());
        Notification::assertSentToTimes($this->owner, UsageAlertNotification::class, 2);

        $this->travelTo(CarbonImmutable::parse('2026-10-01 00:30:00', 'UTC'));
        $this->command('usage:send-alerts')->expectsOutput('Usage alerts: 0 sent, 0 skipped, 0 failed.')->assertSuccessful();
    }

    public function test_the_email_and_inbox_entry_say_what_happens_next(): void
    {
        $this->usage(500_000);

        $this->command('usage:send-alerts')->assertSuccessful();

        $inbox = $this->owner->notifications()->sole();
        $this->assertSame($this->account->name.' has used all of this month’s Monitoring events', $inbox->data['title']);
        $this->assertStringContainsString('500,000 of 500,000 events', (string) $inbox->data['body']);
        $this->assertSame(route('account.billing'), $inbox->data['url']);
    }

    public function test_unlimited_plans_unverified_owners_and_failed_sends(): void
    {
        Notification::fake();
        $this->usage(10_000_000);
        $this->owner->forceFill(['email_verified_at' => null])->save();
        $this->command('usage:send-alerts')->expectsOutput('Usage alerts: 0 sent, 0 skipped, 0 failed.')->assertSuccessful();

        $this->owner->forceFill(['email_verified_at' => now()])->save();
        $this->onMonitoringTier($this->account, 'scale');
        $this->command('usage:send-alerts')->expectsOutput('Usage alerts: 0 sent, 0 skipped, 0 failed.')->assertSuccessful();

        $this->onMonitoringTier($this->account, 'free');
        Notification::shouldReceive('sendNow')->andThrow(new RuntimeException('SMTP down'));
        $this->command('usage:send-alerts')->expectsOutput('Usage alerts: 0 sent, 0 skipped, 1 failed.')->assertExitCode(1);
        $this->assertSame(['failed'], UsageAlertDelivery::query()->pluck('status')->all());
        $this->command('usage:send-alerts --at=bad')->assertExitCode(2);
    }

    private function usage(int $events): void
    {
        TelemetryUsageEntry::factory()->create(['account_id' => $this->account->id, 'event_count' => $events, 'received_at' => now()->subHour()]);
    }
}
