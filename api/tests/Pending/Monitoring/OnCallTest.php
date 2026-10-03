<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Enums\AccountRole;
use App\Enums\AlertDeliveryStatus;
use App\Models\AlertDelivery;
use App\Models\AlertDestination;
use App\Models\OnCallSchedule;
use App\Models\Project;
use App\Models\User;
use App\Notifications\IncidentAlertNotification;
use App\Services\Monitoring\AlertDeliveryRunner;
use App\Services\Monitoring\AlertDispatcher;
use App\Services\Monitoring\OnCall;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class OnCallTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check that turns follow the rotation in order, hand over at the local time (also across a daylight-saving
     * change), and that cover wins while it lasts.
     *
     * @return void
     */
    public function test_turns_rotate_at_the_local_handover_time_and_cover_wins(): void
    {
        [$project, $owner, $amy, $ben, $cat] = $this->team();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'));
        $this->actingAs($owner)->post("/projects/{$project->id}/monitoring/on-call", [
            'name' => 'Primary', 'rotation' => 'weekly', 'handoff_day' => 1, 'handoff_time' => '09:00', 'timezone' => 'Europe/London',
            'starts_on' => '2026-10-05', 'member_ids' => [$amy->id, $ben->id, $cat->id, ''],
        ])->assertRedirect("/projects/{$project->id}/monitoring/on-call");
        $schedule = OnCallSchedule::query()->sole();
        $onCall = app(OnCall::class);
        $at = fn (string $utc): ?string => $onCall->current($schedule, CarbonImmutable::parse($utc, 'UTC'))?->name;

        $this->assertSame('Cat', $at('2026-10-05 07:59:00'));   // 08:59 BST, before the first hand-over
        $this->assertSame('Amy', $at('2026-10-05 08:00:00'));   // 09:00 BST
        $this->assertSame('Ben', $at('2026-10-12 08:30:00'));
        $this->assertSame('Cat', $at('2026-10-19 08:00:00'));
        // The clocks go back on 25 October: 09:00 GMT is 09:00 UTC.
        $this->assertSame('Cat', $at('2026-10-26 08:30:00'));
        $this->assertSame('Amy', $at('2026-10-26 09:00:00'));

        $this->actingAs($owner)->post("/projects/{$project->id}/monitoring/on-call/{$schedule->id}/overrides", ['user_id' => $cat->id, 'starts_at' => '2026-10-13T00:00', 'ends_at' => '2026-10-14T00:00'])->assertRedirect();
        $this->assertSame('Cat', $at('2026-10-13 12:00:00'));
        $this->assertSame('Ben', $at('2026-10-14 12:00:00'));

        $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00:00', 'UTC'));
        $this->actingAs($owner)->get("/projects/{$project->id}/monitoring/on-call")->assertOk()->assertSee('On call now')->assertSee('Cat')->assertSee('Next turns');
    }

    /**
     * Check that an email destination can follow a schedule and alerts go to whoever is on call when they're sent.
     *
     * @return void
     */
    public function test_alerts_reach_whoever_is_on_call(): void
    {
        config(['monitoring.alerts.mailer' => 'alert_smtp']);
        Notification::fake();
        [$project, $owner, $amy, $ben] = $this->team();
        $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'UTC'));
        $schedule = new OnCallSchedule;
        $schedule->forceFill(['account_id' => $project->account_id, 'name' => 'Primary', 'timezone' => 'UTC', 'rotation' => 'daily', 'handoff_time' => '09:00', 'starts_on' => '2026-10-06'])->save();
        $schedule->members()->sync([$amy->id => ['position' => 0], $ben->id => ['position' => 1]]);

        $this->actingAs($owner)->get("/projects/{$project->id}/monitoring/alerts")->assertOk()->assertSee('On call: Primary');
        $this->actingAs($owner)->post("/projects/{$project->id}/monitoring/alerts", ['name' => 'Pager', 'type' => 'email', 'enabled' => '1', 'recipient_user_id' => 'schedule:'.$schedule->id])->assertRedirect();
        $destination = AlertDestination::query()->where('name', 'Pager')->sole();
        $this->assertSame([$schedule->id, null], [$destination->on_call_schedule_id, $destination->recipient_user_id]);
        $this->assertSame('On call: Primary', $destination->targetLabel());

        $send = function () use ($destination): void {
            $delivery = app(AlertDispatcher::class)->queue($destination, ['event' => 'test', 'title' => 'Test alert', 'kind' => 'test']);
            app(AlertDeliveryRunner::class)->process($delivery->id, 0);
            $this->assertSame(AlertDeliveryStatus::Accepted, $delivery->refresh()->status);
        };
        $send();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'UTC'));
        $send();
        $recipients = [];
        Notification::assertSentOnDemand(IncidentAlertNotification::class, function ($notification, $channels, $notifiable) use (&$recipients): bool {
            $recipients[] = $notifiable->routes['mail'];

            return true;
        });
        $this->assertSame([$amy->email, $ben->email], $recipients);
        $this->assertSame(2, AlertDelivery::query()->count());
    }

    /**
     * Make a project whose owner can manage Monitoring, with three verified members.
     *
     * @return array{0: Project, 1: User, 2: User, 3: User, 4: User}
     */
    private function team(): array
    {
        $project = Project::factory()->withServices(['monitoring'])->create();
        $owner = $this->ownerOf($project);
        $people = [];
        foreach (['Amy', 'Ben', 'Cat'] as $name) {
            $person = User::factory()->create(['name' => $name]);
            $this->addMember($project, $person, AccountRole::Member);
            $people[] = $person;
        }

        return [$project, $owner, ...$people];
    }
}
