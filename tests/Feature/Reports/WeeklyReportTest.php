<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\AccountRole;
use App\Models\AnalyticsDailyAggregate;
use App\Models\AnalyticsSite;
use App\Models\Build;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Models\WeeklyReportDelivery;
use App\Notifications\WeeklyReportNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class WeeklyReportTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check that the Monday report sums up each project's week, goes once to members who want it, and skips quiet
     * accounts and people who turned it off.
     *
     * @return void
     */
    public function test_the_weekly_report_sums_up_each_projects_week(): void
    {
        Notification::fake();
        $monday = CarbonImmutable::parse('2026-10-05 00:00:00', 'UTC');
        $this->travelTo($monday->addHours(8));
        $project = Project::factory()->withServices(['deploy', 'monitoring', 'analytics'])->create(['name' => 'Storefront']);
        $owner = $this->ownerOf($project);
        $quietMember = User::factory()->create(['weekly_report_emails' => false]);
        $this->addMember($project, $quietMember, AccountRole::Member);
        $production = $project->environments()->where('slug', 'production')->firstOrFail();

        $provider = Provider::factory()->create(['account_id' => $project->account_id]);
        $website = Website::factory()->create(['server_id' => Server::factory()->create(['provider_id' => $provider->id])->id]);
        $repository = Repository::factory()->create(['website_id' => $website->id, 'project_id' => $project->id, 'provider_id' => $provider->id, 'environment_id' => $production->id]);
        foreach ([Build::STATUS_SUCCEEDED, Build::STATUS_SUCCEEDED, Build::STATUS_FAILED] as $status) {
            Build::factory()->create(['repository_id' => $repository->id, 'environment_id' => $production->id, 'status' => $status, 'created_at' => $monday->subDays(3)]);
        }
        Build::factory()->create(['repository_id' => $repository->id, 'environment_id' => $production->id, 'status' => Build::STATUS_SUCCEEDED, 'created_at' => $monday->subDays(10)]);

        $monitor = Monitor::factory()->create(['environment_id' => $production->id]);
        foreach (['up', 'up', 'up', 'down'] as $index => $outcome) {
            MonitorCheck::factory()->for($monitor)->create(['status' => 'completed', 'outcome' => $outcome, 'scheduled_at' => $monday->subDays(2)->addMinutes($index), 'config_revision' => $monitor->config_revision]);
        }
        Incident::factory()->for($monitor)->create(['title' => 'Checkout down', 'opened_at' => $monday->subDays(2)]);

        $site = AnalyticsSite::factory()->create(['project_id' => $project->id]);
        $this->visits($site, $monday->subDays(2), 330);
        $this->visits($site, $monday->subDays(9), 300);

        Project::factory()->create(['name' => 'Nothing happened']);
        $this->command('reports:send-weekly')->expectsOutputToContain('1 sent, 0 skipped, 0 failed')->assertSuccessful();
        $this->command('reports:send-weekly')->expectsOutputToContain('0 sent, 1 skipped, 0 failed')->assertSuccessful();

        Notification::assertNothingSentTo($quietMember);
        Notification::assertSentTo($owner, WeeklyReportNotification::class, function (WeeklyReportNotification $notification) use ($owner): bool {
            $project = $notification->report['projects'][0];
            $this->assertSame(['Storefront', 3, 1, 1, 1, 75.0, 330, 300], [$project['name'], $project['deploys'], $project['deploys_failed'], $project['incidents'], $project['incidents_open'], $project['uptime'], $project['visits'], $project['visits_before']]);
            $mail = $notification->toMail($owner);
            $body = implode("\n", $mail->introLines);
            $this->assertStringContainsString('3 deploys (1 failed)', $body);
            $this->assertStringContainsString('75% uptime', $body);
            $this->assertStringContainsString('330 visits (up 10%)', $body);
            $this->assertStringContainsString('last week: 3 deploys, 1 incident', (string) $mail->subject);

            return true;
        });
        $this->assertSame(1, WeeklyReportDelivery::query()->where('status', 'sent')->count());
    }

    /**
     * Check that people can turn the weekly report off from their notification settings.
     *
     * @return void
     */
    public function test_people_can_turn_the_weekly_report_off(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/settings/notifications')->assertOk()->assertSee('Email me the weekly report');
        $this->actingAs($user)->put('/settings/notifications/weekly-report', ['weekly_report_emails' => '0'])->assertRedirect('/settings/notifications');
        $this->assertFalse($user->refresh()->weekly_report_emails);
    }

    /**
     * Record a day's visits for a site.
     *
     * @param  AnalyticsSite  $site
     * @param  CarbonImmutable  $day
     * @param  int  $visits
     * @return void
     */
    private function visits(AnalyticsSite $site, CarbonImmutable $day, int $visits): void
    {
        AnalyticsDailyAggregate::query()->create(['site_id' => $site->id, 'local_date' => $day->toDateString(), 'dimension' => 'all', 'dimension_value' => null, 'pageviews' => $visits * 2, 'visits' => $visits, 'visitors' => $visits]);
    }
}
