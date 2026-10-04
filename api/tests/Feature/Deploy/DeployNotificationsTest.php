<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Actions\Deploy\FinishBuild;
use App\Enums\AccountRole;
use App\Enums\AlertDeliveryStatus;
use App\Enums\AlertDestinationType;
use App\Models\AlertDelivery;
use App\Models\AlertDestination;
use App\Models\Build;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Notifications\IncidentAlertNotification;
use App\Services\Deploy\DeployNotifications;
use App\Services\Monitoring\AlertDeliveryRunner;
use App\Services\Monitoring\AlertNotificationTransport;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use ReflectionMethod;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class DeployNotificationsTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * The project whose deploys are announced.
     *
     * @var Project
     */
    private Project $project;

    /**
     * The project's owner.
     *
     * @var User
     */
    private User $owner;

    /**
     * The production environment.
     *
     * @var Environment
     */
    private Environment $production;

    /**
     * The repository that deploys to production.
     *
     * @var Repository
     */
    private Repository $repository;

    /**
     * Set up a project with a repository deploying to production.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $this->project = Project::factory()->withServices(['deploy', 'infrastructure', 'monitoring'])->create();
        $this->owner = $this->ownerOf($this->project);
        $provider = Provider::factory()->create(['account_id' => $this->project->account_id]);
        $website = Website::factory()->create(['server_id' => Server::factory()->create(['provider_id' => $provider->id])->id, 'deployment_slug' => 'shop']);
        $this->production = $this->project->environments()->where('slug', 'production')->firstOrFail();
        $this->repository = Repository::factory()->create(['website_id' => $website->id, 'project_id' => $this->project->id, 'provider_id' => $provider->id, 'environment_id' => $this->production->id]);
        $this->withoutMiddleware(RequirePassword::class);
    }

    /**
     * Check that the Notifications tab saves routes and a finished deploy goes only where its outcome was asked for.
     *
     * @return void
     */
    public function test_finished_deploys_reach_the_destinations_that_asked_for_that_outcome(): void
    {
        $slack = AlertDestination::factory()->slack()->create(['account_id' => $this->project->account_id, 'name' => 'Team Slack']);
        $hook = AlertDestination::factory()->create(['account_id' => $this->project->account_id, 'name' => 'Audit hook']);
        $pager = AlertDestination::factory()->create(['account_id' => $this->project->account_id, 'name' => 'On-call pager', 'type' => AlertDestinationType::PagerDuty]);
        $base = "/api/app/projects/{$this->project->id}/deploy/environments/{$this->production->id}";

        $this->actingAs($this->owner)->getJson($base)->assertOk()->assertSee('Team Slack')->assertSee('Audit hook')->assertDontSee('On-call pager');
        $this->actingAs($this->owner)->putJson("{$base}/notifications", ['destinations' => [
            $slack->id => ['on_success', 'on_failure'], $hook->id => ['on_failure'], $pager->id => ['on_success'],
        ]])->assertJsonRedirect("{$base}?tab=notifications");
        $this->assertSame(2, $this->production->deployNotifications()->count());

        $this->finish(Build::STATUS_SUCCEEDED);
        $delivery = AlertDelivery::query()->sole();
        $this->assertSame([$slack->id, 'deploy_succeeded', 'deploy', 'View deploy', 'Deploy live'], [$delivery->alert_destination_id, $delivery->event, $delivery->payload['kind'], $delivery->payload['url_label'], $delivery->payload['event_label']]);

        $this->finish(Build::STATUS_FAILED, 'Composer install failed');
        $this->assertSame(3, AlertDelivery::query()->count());
        $this->assertEqualsCanonicalizing([$slack->id, $hook->id], AlertDelivery::query()->where('event', 'deploy_failed')->pluck('alert_destination_id')->all());

        // Cancelled deploys say nothing; unticking everything stops a destination hearing.
        $this->finish(Build::STATUS_CANCELED);
        $this->actingAs($this->owner)->putJson("{$base}/notifications", ['destinations' => [$slack->id => ['on_failure']]])->assertSuccessful();
        $this->assertSame([$slack->id], $this->production->deployNotifications()->pluck('alert_destination_id')->all());
        $this->assertSame(3, AlertDelivery::query()->count());
    }

    /**
     * Check that Slack messages and emails for a deploy read as a deploy, not an incident.
     *
     * @return void
     */
    public function test_deploy_messages_read_as_deploys(): void
    {
        $slack = AlertDestination::factory()->slack()->create(['account_id' => $this->project->account_id]);
        $this->production->deployNotifications()->make()->forceFill(['alert_destination_id' => $slack->id, 'on_success' => true, 'on_failure' => true])->save();
        $this->finish(Build::STATUS_FAILED, 'Migrations failed');
        $payload = AlertDelivery::query()->sole()->payload;

        // The chat formats are private to the transport, which posts them over pinned curl; read them directly.
        $format = fn (string $method): string => json_encode((new ReflectionMethod(AlertNotificationTransport::class, $method))->invoke(app(AlertNotificationTransport::class), 'delivery-1', $payload), JSON_THROW_ON_ERROR);
        $message = $format('slackPayload');
        $this->assertStringContainsString('Deploy failed', $message);
        $this->assertStringContainsString('View deploy', $message);
        $this->assertStringNotContainsString('View incident', $message);
        $this->assertStringContainsString((string) 0xDC2626, $format('discordPayload'));

        $mail = (new IncidentAlertNotification('delivery-1', $payload))->toMail($this->owner);
        $this->assertStringContainsString('Deploy failed — Production', (string) $mail->subject);
        $this->assertSame('View deploy', $mail->actionText);
    }

    /**
     * Check a deploy waiting for approval reaches Slack with Approve and Reject buttons, which open a page with that
     * decision ready to confirm; only someone other than the requester can confirm it, and nothing changes on opening.
     *
     * @return void
     */
    public function test_deploys_are_approved_from_slack(): void
    {
        $slack = AlertDestination::factory()->slack()->create(['account_id' => $this->project->account_id]);
        $this->production->deployNotifications()->make()->forceFill(['alert_destination_id' => $slack->id, 'on_approval' => true])->save();
        $requester = User::factory()->create();
        $this->addMember($this->project, $requester, AccountRole::Admin);
        $build = Build::factory()->create(['repository_id' => $this->repository->id, 'environment_id' => $this->production->id, 'status' => Build::STATUS_AWAITING_APPROVAL, 'requested_by' => $requester->id]);
        app(DeployNotifications::class)->send($build, 'deploy_approval');
        $payload = AlertDelivery::query()->sole()->payload;
        $message = json_encode((new ReflectionMethod(AlertNotificationTransport::class, 'slackPayload'))->invoke(app(AlertNotificationTransport::class), 'delivery-1', $payload), JSON_THROW_ON_ERROR);
        $approve = route('deploy.builds.decide', [$this->project, $build->id, 'decision' => 'approve']);
        $this->assertStringContainsString('"style":"primary"', $message);
        $this->assertStringContainsString(str_replace('/', '\\/', $approve), $message);
        $this->assertStringContainsString('"style":"danger"', $message);

        $this->actingAs($requester)->getJson(self::api($approve))->assertOk()->assertJsonPath('reason', 'Someone else has to approve your deploy.')->assertJsonPath('canApprove', false);
        $this->actingAs($this->owner)->getJson(self::api($approve))->assertOk()->assertJsonPath('canApprove', true)->assertJsonPath('build.awaitingApproval', true);
        $this->assertSame(Build::STATUS_AWAITING_APPROVAL, $build->refresh()->status, 'Opening the link changes nothing.');
        $this->actingAs($this->owner)->postJson(route('app.deploy.builds.review', [$this->project, $build->id]), ['decision' => 'reject'])->assertSuccessful();
        $this->assertSame(Build::STATUS_REJECTED, $build->refresh()->status);
        $this->actingAs($this->owner)->getJson(self::api($approve))->assertOk()->assertJsonPath('build.awaitingApproval', false);
    }

    /**
     * Check that a deploy notification is actually delivered, not cancelled for lacking an incident.
     *
     * @return void
     */
    public function test_deploy_notifications_are_delivered(): void
    {
        config(['monitoring.alerts.mailer' => 'alert_smtp']);
        Notification::fake();
        $email = AlertDestination::factory()->email()->create(['account_id' => $this->project->account_id]);
        $this->production->deployNotifications()->make()->forceFill(['alert_destination_id' => $email->id, 'on_success' => true, 'on_failure' => true])->save();
        $this->finish(Build::STATUS_SUCCEEDED);
        $delivery = AlertDelivery::query()->sole();

        app(AlertDeliveryRunner::class)->process($delivery->id, 0);

        $this->assertSame(AlertDeliveryStatus::Accepted, $delivery->refresh()->status);
        Notification::assertSentOnDemand(IncidentAlertNotification::class);
    }

    /**
     * Start a deploy on the repository and finish it with the given status.
     *
     * @param  string  $status
     * @param  string|null  $message
     * @return Build
     */
    private function finish(string $status, ?string $message = null): Build
    {
        $build = Build::factory()->create(['repository_id' => $this->repository->id, 'environment_id' => $this->production->id, 'status' => Build::STATUS_RUNNING]);
        app(FinishBuild::class)->handle($build, $status, $message);

        return $build;
    }

    /**
     * Point a page's address (from route()) at the app's API.
     *
     * @param  string  $url
     * @return string
     */
    private static function api(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return '/api/app'.$path.(($query = parse_url($url, PHP_URL_QUERY)) ? '?'.$query : '');
    }
}
