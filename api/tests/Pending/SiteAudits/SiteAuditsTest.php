<?php

declare(strict_types=1);

namespace Tests\Feature\SiteAudits;

use App\Contracts\Monitoring\DnsResolver;
use App\Contracts\SiteAudits\AuditAnalyst;
use App\Contracts\SiteAudits\AuditBrowser;
use App\Enums\AccountRole;
use App\Enums\SelectionKind;
use App\Models\BillingSelection;
use App\Models\Project;
use App\Models\SiteAudit;
use App\Models\SiteAuditRun;
use App\Models\UsageRecord;
use App\Models\User;
use App\Platform\ServiceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeAuditAnalyst;
use Tests\Fakes\FakeAuditBrowser;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class SiteAuditsTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    private FakeAuditBrowser $browser;

    private FakeAuditAnalyst $analyst;

    private Project $project;

    private User $owner;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->browser = new FakeAuditBrowser;
        $this->analyst = new FakeAuditAnalyst;
        $this->app->instance(AuditBrowser::class, $this->browser);
        $this->app->instance(AuditAnalyst::class, $this->analyst);
        $this->project = Project::factory()->withServices(['audit'])->create();
        $this->owner = $this->ownerOf($this->project);
        $this->owner->forceFill(['current_account_id' => $this->project->account_id])->save();
        $this->base = "/api/app/projects/{$this->project->id}/audit";
    }

    /**
     * The wizard creates an audit and its first run; the visitor takes each journey on the site and the competitor,
     * and the report has both sites' scores, the findings with outlined screenshots and a mock-up, and every step.
     */
    public function test_an_audit_runs_and_its_report_compares_the_site_with_its_competitor(): void
    {
        $created = $this->actingAs($this->owner)->postJson($this->base, [
            'name' => 'Shop', 'url' => 'shop.example', 'goals' => ['pricing'], 'custom_goal' => 'Find the returns policy.',
            'competitors' => [['url' => 'https://rival.example', 'name' => 'Rival']], 'schedule' => 'none',
        ])->assertCreated()->assertJsonPath('audit.url', 'https://shop.example/')->assertJsonPath('audit.competitors.0.name', 'Rival');

        $run = $this->findRun($created->json('runId'));
        $this->assertSame('done', $run->status->value);
        $this->assertSame(['input' => 1200, 'output' => 300], ['input' => $run->input_tokens, 'output' => $run->output_tokens]);
        $this->assertContains('checks https://rival.example/', $this->browser->log);
        $this->assertContains('render', $this->browser->log);
        $this->assertCount(8, $this->analyst->goals, 'Two journeys on two sites, two decisions each (click, then finish).');
        $this->assertContains('Find the returns policy.', $this->analyst->goals);
        $this->assertSame(1, (int) UsageRecord::query()->where('account_id', $this->project->account_id)->where('meter', 'audit.runs')->sum('quantity'));

        $report = $this->actingAs($this->owner)->getJson("{$this->base}/runs/{$run->id}")->assertOk()->json('report');
        $this->assertSame(['site', 'competitor:'.SiteAudit::query()->firstOrFail()->competitors()->firstOrFail()->id], array_column($report['sites'], 'key'));
        $this->assertSame('Shop', $report['sites'][0]['name']);
        $categories = array_column($report['sites'][0]['categories'], 'score', 'key');
        $this->assertSame(['performance', 'accessibility', 'seo', 'mobile', 'navigation', 'conversion', 'content', 'trust'], array_keys($categories));
        $this->assertLessThan(array_column($report['sites'][1]['categories'], 'score', 'key')['conversion'], $categories['conversion']);
        $this->assertSame('The sign-up button is easy to miss', $report['findings'][0]['title']);
        $this->assertSame([['x' => 1000, 'y' => 20, 'width' => 100, 'height' => 36]], $report['findings'][0]['boxes']);
        $this->assertNotNull($report['findings'][0]['mockupUrl']);
        $this->assertNull($report['findings'][1]['mockupUrl']);
        $this->assertCount(4, $report['journeys']);
        $this->assertSame('click', $report['journeys'][0]['steps'][0]['action']['type']);
        $this->assertSame('Pricing', $report['journeys'][0]['steps'][0]['action']['label']);
        $this->assertSame('Easy enough.', $report['journeys'][0]['summary']);

        $this->actingAs($this->owner)->get($report['findings'][0]['screenshotUrl'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->actingAs($this->owner)->get($report['findings'][0]['mockupUrl'])->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($this->owner)->get("{$this->base}/runs/{$run->id}/files/missing.jpg")->assertNotFound();

        $this->actingAs($this->owner)->getJson($this->base)->assertOk()->assertJsonPath('audits.0.latestRun.score', $run->score)
            ->assertJsonPath('plan.runsUsed', 1)->assertJsonPath('plan.runsAllowance', 1)->assertJsonPath('plan.canManage', true);
    }

    /**
     * The Free plan allows one audited site, one competitor, one audit a month and no schedule, and says so.
     */
    public function test_the_plan_limits_audits(): void
    {
        $audit = ['url' => 'shop.example', 'goals' => ['understand'], 'competitors' => [], 'schedule' => 'none'];
        $this->actingAs($this->owner)->postJson($this->base, [...$audit, 'schedule' => 'weekly'])->assertUnprocessable()->assertJsonValidationErrors('schedule');
        $this->actingAs($this->owner)->postJson($this->base, [...$audit, 'competitors' => [['url' => 'a.example'], ['url' => 'b.example']]])
            ->assertUnprocessable()->assertJsonValidationErrors('competitors');
        $this->actingAs($this->owner)->postJson($this->base, [...$audit, 'goals' => []])->assertUnprocessable()->assertJsonValidationErrors('goals');
        $this->actingAs($this->owner)->postJson($this->base, [...$audit, 'url' => 'localhost'])->assertUnprocessable()->assertJsonValidationErrors('url');

        $id = $this->actingAs($this->owner)->postJson($this->base, $audit)->assertCreated()->json('audit.id');
        $this->actingAs($this->owner)->postJson($this->base, [...$audit, 'url' => 'other.example'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($this->owner)->postJson("{$this->base}/{$id}/runs")->assertUnprocessable()->assertJsonValidationErrors('audit');

        (new BillingSelection)->forceFill(['account_id' => $this->project->account_id, 'service' => 'audit', 'kind' => SelectionKind::Tier, 'item_key' => 'pro'])->save();
        $this->actingAs($this->owner)->putJson("{$this->base}/{$id}", [...$audit, 'schedule' => 'monthly'])->assertOk()->assertJsonPath('audit.schedule', 'monthly');
        $run = $this->actingAs($this->owner)->postJson("{$this->base}/{$id}/runs")->assertStatus(202)->assertJsonPath('run.status', 'queued')->json('run.id');
        $this->assertSame('done', $this->findRun($run)->status->value);
    }

    /**
     * Scheduled audits run when due and move to their next date.
     */
    public function test_scheduled_audits_run_when_due(): void
    {
        (new BillingSelection)->forceFill(['account_id' => $this->project->account_id, 'service' => 'audit', 'kind' => SelectionKind::Tier, 'item_key' => 'business'])->save();
        $id = $this->actingAs($this->owner)->postJson($this->base, ['url' => 'shop.example', 'goals' => ['pricing'], 'schedule' => 'weekly', 'run_now' => false])->assertCreated()->json('audit.id');
        $audit = SiteAudit::query()->findOrFail((int) $id);
        $this->assertTrue($audit->next_run_at?->isNextWeek() || $audit->next_run_at?->diffInDays(now(), true) >= 6);

        $this->assertSame(0, Artisan::call('site-audits:run-scheduled'));
        $this->assertStringContainsString('Queued 0 audits.', Artisan::output());
        $audit->forceFill(['next_run_at' => now()->subMinute()])->save();
        $this->assertSame(0, Artisan::call('site-audits:run-scheduled'));
        $this->assertStringContainsString('Queued 1 audits.', Artisan::output());
        $this->assertSame('scheduled', $audit->runs()->firstOrFail()->trigger);
        $this->assertTrue($audit->refresh()->next_run_at?->isFuture());
    }

    /**
     * A site that can't be reached fails the run with the reason; a competitor that can't be reached is skipped.
     */
    public function test_unreachable_sites(): void
    {
        $this->browser->failing = ['https://rival.example/'];
        $id = $this->actingAs($this->owner)->postJson($this->base, ['url' => 'shop.example', 'goals' => ['pricing'], 'competitors' => [['url' => 'rival.example']], 'schedule' => 'none'])
            ->assertCreated()->json('runId');
        $this->assertSame('done', $this->findRun($id)->status->value);
        $this->assertCount(1, $this->findRun($id)->scores ?? [], 'Only the site itself is scored.');

        $this->browser->failing = ['https://down.example/'];
        $audit = SiteAudit::query()->firstOrFail();
        $audit->forceFill(['url' => 'https://down.example/'])->save();
        (new BillingSelection)->forceFill(['account_id' => $this->project->account_id, 'service' => 'audit', 'kind' => SelectionKind::Tier, 'item_key' => 'pro'])->save();
        $run = $this->findRun($this->actingAs($this->owner)->postJson("{$this->base}/{$audit->id}/runs")->assertStatus(202)->json('run.id'));
        $this->assertSame('failed', $run->status->value);
        $this->assertSame('The site couldn’t be reached.', $run->error);
        $this->assertContains('close', $this->browser->log);
    }

    /**
     * Competitors are suggested from the site's home page (a public address only) and Claude's picks, without the site itself.
     */
    public function test_competitor_suggestions(): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturnUsing(fn (string $host): array => $host === 'internal.example' ? ['10.0.0.5'] : ['93.184.216.34']);
        Http::fake(['https://shop.example/' => Http::response('<html><head><title>Shop — bikes</title><meta name="description" content="We sell bikes."></head><body><script>x</script><h1>Bikes</h1></body></html>')]);
        $this->analyst->competitors = [
            ['name' => 'Rival', 'url' => 'rival.example', 'reason' => 'Also sells bikes.'],
            ['name' => 'Ourselves', 'url' => 'https://shop.example/', 'reason' => 'Same site.'],
        ];

        $this->actingAs($this->owner)->postJson("{$this->base}/competitor-suggestions", ['url' => 'shop.example'])->assertOk()
            ->assertExactJson(['competitors' => [['name' => 'Rival', 'url' => 'https://rival.example/', 'reason' => 'Also sells bikes.']]]);
        $this->actingAs($this->owner)->postJson("{$this->base}/competitor-suggestions", ['url' => 'internal.example'])->assertUnprocessable()->assertJsonValidationErrors('url');
    }

    /**
     * Only people in the account see its audits; members who can't manage projects can read but not change them, and
     * projects without Audit don't have the pages.
     */
    public function test_access(): void
    {
        $id = $this->actingAs($this->owner)->postJson($this->base, ['url' => 'shop.example', 'goals' => ['pricing'], 'schedule' => 'none', 'run_now' => false])->json('audit.id');

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->getJson($this->base)->assertNotFound();
        $this->actingAs($stranger)->getJson("{$this->base}/{$id}")->assertNotFound();

        $viewer = User::factory()->create();
        $this->addMember($this->project, $viewer, AccountRole::Viewer);
        $this->actingAs($viewer)->getJson("{$this->base}/{$id}")->assertOk()->assertJsonPath('audit.plan.canManage', false);
        $this->actingAs($viewer)->postJson("{$this->base}/{$id}/runs")->assertForbidden();
        $this->actingAs($viewer)->deleteJson("{$this->base}/{$id}")->assertForbidden();

        $other = Project::factory()->create(['account_id' => $this->project->account_id]);
        $this->actingAs($this->owner)->getJson("/api/app/projects/{$other->id}/audit/{$id}")->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson($this->base)->assertUnauthorized();

        $this->actingAs($this->owner)->deleteJson("{$this->base}/{$id}")->assertNoContent();
        $this->assertSame(0, SiteAudit::query()->count());
    }

    /**
     * Switched off (the production default until the server can run Chromium), Audit isn't a service: it's in no
     * navigation or plan, and projects can't reach its pages.
     */
    public function test_audit_can_be_switched_off(): void
    {
        config(['site_audits.enabled' => false]);
        $this->app->forgetInstance(ServiceRegistry::class);

        $this->assertNotContains('audit', app(ServiceRegistry::class)->keys());
        $this->actingAs($this->owner)->getJson($this->base)->assertNotFound();
        $this->get('/features/audit')->assertNotFound();
    }

    /**
     * Find a run by the id an API response gave.
     */
    private function findRun(mixed $id): SiteAuditRun
    {
        return SiteAuditRun::query()->findOrFail((int) $id);
    }
}
