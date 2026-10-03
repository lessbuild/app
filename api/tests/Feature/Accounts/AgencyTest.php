<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Models\AnalyticsSite;
use App\Models\Client;
use App\Models\Project;
use App\Models\Server;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\Website;
use App\Notifications\ClientReportNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class AgencyTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * The client's project.
     *
     * @var Project
     */
    private Project $project;

    /**
     * The agency's owner.
     *
     * @var User
     */
    private User $owner;

    /**
     * Set up an agency account with one project and its owner signed in to it.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->project = Project::factory()->withServices(['deploy'])->create(['name' => 'Bakery site']);
        $this->owner = $this->ownerOf($this->project);
        $this->owner->forceFill(['current_account_id' => $this->project->account_id])->save();
    }

    /**
     * Check branding shows on status pages and shared reports only on a plan with white labelling, replacing
     * "Powered by", and bad logos or colours are refused.
     *
     * @return void
     */
    public function test_white_label_branding_needs_the_plan(): void
    {
        $page = StatusPage::factory()->create(['account_id' => $this->project->account_id]);
        $this->actingAs($this->owner)->put('/account/clients/branding', ['brand_name' => 'Acme', 'brand_logo_url' => 'http://acme.test/logo.png'])->assertSessionHasErrors('brand_logo_url');
        $this->actingAs($this->owner)->put('/account/clients/branding', ['brand_name' => 'Acme', 'brand_color' => 'blue'])->assertSessionHasErrors('brand_color');
        $this->actingAs($this->owner)->put('/account/clients/branding', ['brand_name' => 'Acme Studio', 'brand_logo_url' => 'https://acme.test/logo.png', 'brand_color' => '#1F6FEB'])->assertRedirect();
        $this->actingAs($this->owner)->get('/account/clients')->assertOk()->assertSee('comes with the Deploy Team plan');

        $this->get($page->publicUrl())->assertOk()->assertSee('Powered by')->assertDontSee('https://acme.test/logo.png');
        $this->onTier($this->project, 'deploy', 'team');
        $this->get($page->publicUrl())->assertOk()->assertDontSee('Powered by')->assertSee('https://acme.test/logo.png')->assertSee('--ui-primary: #1f6feb', false)->assertSee('Acme Studio');
    }

    /**
     * Check a client's monthly report covers their projects with costs marked up, is sent once a month in the
     * agency's name, and the costs CSV lists it.
     *
     * @return void
     */
    public function test_clients_get_monthly_reports_and_costs(): void
    {
        Notification::fake();
        $this->onTier($this->project, 'deploy', 'team');
        $this->actingAs($this->owner)->put('/account/clients/branding', ['brand_name' => 'Acme Studio']);
        $server = Server::factory()->create(['account_id' => $this->project->account_id, 'monthly_cost' => 20, 'monthly_cost_currency' => 'USD']);
        Website::factory()->create(['server_id' => $server->id, 'account_id' => $this->project->account_id, 'environment_id' => $this->project->environments()->where('slug', 'production')->firstOrFail()->id]);
        AnalyticsSite::factory()->create(['project_id' => $this->project->id]);

        $this->actingAs($this->owner)->post('/account/clients', ['name' => 'Bakery', 'emails' => 'owner@bakery.test, nope', 'project_ids' => [$this->project->id]])->assertSessionHasErrors('emails');
        $this->actingAs($this->owner)->post('/account/clients', ['name' => 'Bakery', 'emails' => 'owner@bakery.test', 'project_ids' => [$this->project->id, 'someone-elses'], 'markup_percent' => 50, 'monthly_report' => '1'])->assertRedirect();
        $client = Client::query()->sole();
        $this->assertSame([$this->project->id], $client->project_ids);

        // Deploy Team is $49 for the one project using Deploy, plus the $20 server, with 50% on top.
        $this->actingAs($this->owner)->get("/account/clients/{$client->id}/report")->assertOk()->assertSee('Bakery site')->assertSee('$103.50');
        $csv = $this->actingAs($this->owner)->get('/account/clients/costs.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('Bakery,"Bakery site",USD,103.50,50', $csv);

        $this->command('clients:send-reports')->expectsOutput('Sent reports to 1 clients.')->assertExitCode(0);
        $this->command('clients:send-reports')->expectsOutput('Sent reports to 0 clients.')->assertExitCode(0);
        Notification::assertSentOnDemand(ClientReportNotification::class, function (ClientReportNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool {
            $mail = $notification->toMail($notifiable);

            return $notifiable->routes['mail'] === 'owner@bakery.test' && $notification->sender === 'Acme Studio' && str_contains(implode(' ', $mail->introLines), 'Bakery site');
        });
    }
}
