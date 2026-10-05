<?php

declare(strict_types=1);

namespace Tests\Feature\StatusPages;

use App\Enums\AccountRole;
use App\Enums\AuditAction;
use App\Models\AuditEntry;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\StatusPage;
use App\Models\StatusSubscription;
use App\Models\StatusUpdate;
use App\Models\User;
use App\Notifications\StatusUpdateNotification;
use Database\Factories\MonitorFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class StatusPageManagementTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = Project::factory()->withServices(['monitoring'])->create();
        $this->owner = $this->ownerOf($this->project);
        $this->base = "/api/app/projects/{$this->project->id}/monitoring/status-pages";
    }

    public function test_owner_publishes_a_page_from_monitors_in_any_of_the_accounts_projects(): void
    {
        $monitor = Monitor::factory()->create(['environment_id' => MonitorFactory::environment($this->project), 'name' => 'Payments API', 'request_url' => 'https://private.internal.example/health']);
        $other = Project::factory()->for($this->project->account)->withServices(['monitoring'])->create();
        $second = Monitor::factory()->create(['environment_id' => MonitorFactory::environment($other), 'name' => 'Dashboard']);

        $this->actingAs($this->owner)->getJson("{$this->base}/create")->assertOk()->assertJsonHasText('Payments API')->assertJsonHasText('Dashboard');
        $this->actingAs($this->owner)->postJson($this->base, [
            'name' => 'Acme status', 'slug' => 'Acme Status', 'description' => 'Live health for customers.', 'published' => '1', 'monitor_ids' => [$second->id, $monitor->id],
        ])->assertSuccessful();

        $page = StatusPage::query()->sole();
        $this->assertSame('acme-status', $page->slug);
        $this->assertTrue($page->published);
        $this->assertSame([$second->id, $monitor->id], $page->components()->pluck('monitor_id')->all());
        $this->assertSame(['Dashboard', 'Payments API'], $page->components()->pluck('label')->all());
        $this->assertSame(AuditAction::StatusPageCreated, AuditEntry::query()->where('account_id', $this->project->account_id)->sole()->action);
        $this->actingAs($this->owner)->getJson($this->base)->assertOk()->assertJsonHasText('Acme status')->assertJsonFragment(['slug' => 'acme-status'])->assertJsonPath('pages.0.preview.0.health', 'Unknown')->assertJsonPath('pages.0.allUp', false)->assertJsonPath('pages.0.domain', null);
        $this->actingAs($this->owner)->getJson("{$this->base}/{$page->id}")->assertOk()->assertJsonHasText('Payments API')->assertJsonHasText(route('status.show', 'acme-status'));
        $this->getJson('/api/app/status/acme-status')->assertOk()->assertJsonHasText('Acme status')->assertJsonHasText('Payments API')->assertJsonLacksText('private.internal.example');
    }

    public function test_slugs_are_made_from_the_name_unique_and_kept_on_edit(): void
    {
        StatusPage::factory()->create(['slug' => 'taken']);

        $this->actingAs($this->owner)->postJson($this->base, ['name' => 'Taken', 'slug' => 'taken'])->assertJsonValidationErrors(['slug' => 'That public address is already taken.']);
        $this->actingAs($this->owner)->postJson($this->base, ['name' => 'Taken', 'slug' => 'subscriptions'])->assertJsonValidationErrors('slug');
        $this->actingAs($this->owner)->postJson($this->base, ['name' => 'Shop status!'])->assertSuccessful();
        $page = StatusPage::query()->where('account_id', $this->project->account_id)->sole();
        $this->assertMatchesRegularExpression('/^shop-status-[a-z0-9]{6}$/', $page->slug);

        $this->actingAs($this->owner)->putJson("{$this->base}/{$page->id}", ['name' => 'Renamed'])->assertJsonRedirect("{$this->base}/{$page->id}");
        $this->assertSame($page->slug, $this->reload($page)->slug);
        $this->assertFalse($this->reload($page)->published);
    }

    public function test_monitors_from_other_accounts_are_refused(): void
    {
        $foreign = Monitor::factory()->create();

        $this->actingAs($this->owner)->postJson($this->base, ['name' => 'Leaky', 'monitor_ids' => [$foreign->id]])->assertJsonValidationErrors('monitor_ids');
        $this->assertDatabaseCount('status_pages', 0);
    }

    public function test_failed_edit_redisplays_without_reselecting_cleared_choices(): void
    {
        $monitor = Monitor::factory()->create(['environment_id' => MonitorFactory::environment($this->project)]);
        $page = StatusPage::factory()->for($this->project->account)->create(['name' => 'Live']);
        $page->components()->create(['monitor_id' => $monitor->id, 'label' => 'Payments', 'position' => 0]);

        // The dialog keeps what was typed; the API only reports the error and changes nothing.
        $this->actingAs($this->owner)->putJson("{$this->base}/{$page->id}", ['name' => '', 'description' => 'Draft after clearing.'])->assertJsonValidationErrors('name');
        $page->refresh();
        $this->assertSame(['Live', true, 1], [$page->name, (bool) $page->published, $page->components()->count()]);
    }

    public function test_viewers_see_pages_but_only_account_admins_manage_them(): void
    {
        $page = StatusPage::factory()->for($this->project->account)->create(['name' => 'Customer status']);
        $member = User::factory()->create();
        $this->addMember($this->project, $member, AccountRole::Member);
        $viewer = User::factory()->create();
        $this->addMember($this->project, $viewer, AccountRole::Viewer);

        $this->actingAs($viewer)->getJson($this->base)->assertOk()->assertJsonHasText('Customer status')->assertJsonLacksText('Add a status page');
        $this->actingAs($viewer)->getJson("{$this->base}/{$page->id}")->assertOk()->assertJsonLacksText('Post update');
        foreach ([$viewer, $member] as $user) {
            $this->actingAs($user)->getJson("{$this->base}/create")->assertForbidden();
            $this->actingAs($user)->postJson($this->base, ['name' => 'Nope'])->assertForbidden();
            $this->actingAs($user)->deleteJson("{$this->base}/{$page->id}")->assertForbidden();
            $this->actingAs($user)->postJson("{$this->base}/{$page->id}/updates", $this->update())->assertForbidden();
        }
        $this->assertModelExists($page);
        $this->assertDatabaseCount('status_updates', 0);
        $foreign = StatusPage::factory()->create();
        $this->actingAs($this->owner)->getJson("{$this->base}/{$foreign->id}")->assertNotFound();
        $this->actingAs($this->owner)->deleteJson("{$this->base}/{$foreign->id}")->assertNotFound();
    }

    public function test_deleting_a_page_takes_it_offline(): void
    {
        $page = StatusPage::factory()->for($this->project->account)->create(['slug' => 'going-away']);
        StatusUpdate::factory()->for($page)->create();

        $this->actingAs($this->owner)->deleteJson("{$this->base}/{$page->id}")->assertJsonRedirect($this->base);

        $this->assertModelMissing($page);
        $this->assertDatabaseCount('status_updates', 0);
        $this->getJson('/api/app/status/going-away')->assertNotFound();
    }

    public function test_posting_and_changing_updates_emails_confirmed_subscribers_only(): void
    {
        Notification::fake();
        $page = StatusPage::factory()->for($this->project->account)->create();
        $confirmed = StatusSubscription::factory()->for($page)->create(['email' => 'fan@example.com']);
        StatusSubscription::factory()->for($page)->pending()->create(['email' => 'pending@example.com']);

        $this->actingAs($this->owner)->postJson("{$this->base}/{$page->id}/updates", $this->update())->assertJsonRedirect("{$this->base}/{$page->id}")
            ->assertJsonPath('message', 'Update posted. Subscribers are being emailed.');
        $update = StatusUpdate::query()->sole();
        $this->assertSame($this->owner->id, $update->created_by);
        $this->assertNull($update->resolved_at);
        Notification::assertSentOnDemandTimes(StatusUpdateNotification::class, 1);
        Notification::assertSentOnDemand(StatusUpdateNotification::class, fn (StatusUpdateNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'fan@example.com' && $notification->subscription->is($confirmed));

        $this->actingAs($this->owner)->putJson("{$this->base}/{$page->id}/updates/{$update->id}", $this->update(['status' => 'resolved', 'root_cause' => 'A bad deploy.']))->assertSuccessful();
        $update->refresh();
        $this->assertSame('resolved', $update->status);
        $this->assertNotNull($update->resolved_at);
        $this->assertSame('A bad deploy.', $update->root_cause);
        Notification::assertSentOnDemandTimes(StatusUpdateNotification::class, 2);
        $this->assertSame(
            [AuditAction::StatusUpdatePosted, AuditAction::StatusUpdateChanged],
            AuditEntry::query()->where('account_id', $this->project->account_id)->orderBy('id')->pluck('action')->all(),
        );

        $mail = (new StatusUpdateNotification($update, $confirmed))->toMail($confirmed);
        $this->assertSame('[Resolved] Checkout is slow', $mail->subject);
        $this->assertStringContainsString(route('status.subscriptions.unsubscribe', [$confirmed->id, $confirmed->unsubscribe_token]), (string) $mail->render());
    }

    public function test_draft_pages_keep_updates_but_email_nobody(): void
    {
        Notification::fake();
        $page = StatusPage::factory()->draft()->for($this->project->account)->create();
        StatusSubscription::factory()->for($page)->create();

        $this->actingAs($this->owner)->postJson("{$this->base}/{$page->id}/updates", $this->update())->assertJsonPath('message', 'Update saved. It shows once the page is published.');

        $this->assertDatabaseCount('status_updates', 1);
        Notification::assertNothingSent();
    }

    public function test_update_status_must_match_its_type(): void
    {
        $page = StatusPage::factory()->for($this->project->account)->create();

        $this->actingAs($this->owner)->postJson("{$this->base}/{$page->id}/updates", $this->update(['kind' => 'maintenance', 'status' => 'investigating']))
            ->assertJsonValidationErrors(['status' => 'Choose a status that matches the type of update.']);
        $this->actingAs($this->owner)->postJson("{$this->base}/{$page->id}/updates", $this->update(['ends_at' => '2026-01-01T00:00']))->assertJsonValidationErrors('ends_at');
        $this->assertDatabaseCount('status_updates', 0);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function update(array $overrides = []): array
    {
        return [
            'kind' => 'incident', 'status' => 'investigating', 'severity' => 'major', 'title' => 'Checkout is slow',
            'message' => 'We’re looking into slow checkouts.', 'starts_at' => now('UTC')->format('Y-m-d\TH:i'), ...$overrides,
        ];
    }
}
