<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Enums\AccountRole;
use App\Models\Issue;
use App\Models\IssueDigestDelivery;
use App\Models\Project;
use App\Models\User;
use App\Notifications\IssueDigestNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class IssueDigestTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-20 08:00:00', 'UTC'));
        $this->project = Project::factory()->withServices(['monitoring'])->create(['name' => 'Shop']);
        $this->owner = $this->ownerOf($this->project);
    }

    public function test_owners_get_the_digest_by_default_and_members_can_opt_in(): void
    {
        Notification::fake();
        $member = User::factory()->create();
        $this->addMember($this->project, $member, AccountRole::Member);
        $viewer = User::factory()->create();
        $this->addMember($this->project, $viewer, AccountRole::Viewer);
        foreach ([$member, $viewer] as $user) {
            $user->forceFill(['current_account_id' => $this->project->account_id])->save();
        }
        Issue::factory()->for($this->project)->create(['title' => 'Payment declined', 'first_seen_at' => now()->subHours(3), 'severity' => 'critical']);
        Issue::factory()->for($this->project)->resolved()->create(['title' => 'Slow login', 'first_seen_at' => now()->subDays(3), 'resolved_at' => now()->subHour()]);
        Issue::factory()->for($this->project)->create(['title' => 'Old news', 'first_seen_at' => now()->subDays(5)]);
        Issue::factory()->create(['title' => 'Someone else’s bug', 'first_seen_at' => now()->subHour()]);

        $this->actingAs($member)->put('/settings/notifications', ['issue_digest' => '1'])->assertRedirect('/settings/notifications');
        $this->actingAs($viewer)->put('/settings/notifications', ['issue_digest' => '1'])->assertRedirect();
        $this->command('issues:send-digest')->expectsOutput('Issue digests: 2 sent, 0 skipped, 0 failed.')->assertSuccessful();

        Notification::assertSentTo([$this->owner, $member], IssueDigestNotification::class, function (IssueDigestNotification $notification): bool {
            $digest = $notification->digest;

            return array_column($digest['new'], 'title') === ['Payment declined'] && array_column($digest['resolved'], 'title') === ['Slow login']
                && $digest['open'] === 2 && $digest['critical'] === 1 && $digest['new'][0]['project'] === 'Shop';
        });
        Notification::assertNotSentTo($viewer, IssueDigestNotification::class);
        $mail = (string) (new IssueDigestNotification(Notification::sent($this->owner, IssueDigestNotification::class)->first()->digest))->toMail($this->owner)->render();
        $this->assertStringContainsString('Payment declined', $mail);
        $this->assertStringNotContainsString('Someone else', $mail);

        $this->command('issues:send-digest')->expectsOutput('Issue digests: 0 sent, 2 skipped, 0 failed.')->assertSuccessful();
        $this->assertSame(2, IssueDigestDelivery::query()->where('status', 'sent')->count());
    }

    public function test_owners_can_opt_out_and_quiet_accounts_get_nothing(): void
    {
        Notification::fake();
        $this->command('issues:send-digest')->expectsOutput('Issue digests: 0 sent, 0 skipped, 0 failed.')->assertSuccessful();

        Issue::factory()->for($this->project)->create(['first_seen_at' => now()->subHour()]);
        $this->actingAs($this->owner)->get('/settings/notifications')->assertOk()->assertSee('Email me the daily issue digest');
        $this->actingAs($this->owner)->put('/settings/notifications', ['issue_digest' => '0'])->assertRedirect();
        $this->command('issues:send-digest')->expectsOutput('Issue digests: 0 sent, 0 skipped, 0 failed.')->assertSuccessful();
        Notification::assertNothingSent();
        $this->command('issues:send-digest --from=2026-09-21 --until=2026-09-20')->assertExitCode(2);
    }
}
