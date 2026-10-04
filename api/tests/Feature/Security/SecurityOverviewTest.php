<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Contracts\Security\DomainProbe;
use App\Enums\AccountRole;
use App\Models\Domain;
use App\Models\Project;
use App\Models\SecurityFinding;
use App\Models\SecurityScan;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteDomain;
use App\Services\Security\ScanSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class SecurityOverviewTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * The fake network the domain check sees.
     *
     * @var FakeDomainProbe
     */
    private FakeDomainProbe $probe;

    /**
     * Swap the network for a fake.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->probe = new FakeDomainProbe;
        $this->app->instance(DomainProbe::class, $this->probe);
    }

    /**
     * Check the domain check's findings, the overview and score, ignoring and reopening, a rescan resolving what was
     * fixed, the schedule, and that viewers can look but not act.
     *
     * @return void
     */
    public function test_domains_are_checked_and_findings_follow_the_fixes(): void
    {
        $project = Project::factory()->withServices(['security', 'infrastructure'])->create();
        $owner = $this->ownerOf($project);
        (new Domain)->forceFill(['project_id' => $project->id, 'hostname' => 'example.com', 'verification_token' => 'x', 'verified_at' => now()])->save();
        $environment = $project->environments()->firstOrFail();
        $website = Website::factory()->create(['account_id' => $project->account_id, 'environment_id' => $environment->id]);
        foreach (['shop.example.com' => 'primary', 'old.example.com' => 'alias'] as $host => $type) {
            (new WebsiteDomain)->forceFill(['website_id' => $website->id, 'hostname' => $host, 'type' => $type, 'is_temporary' => false, 'dns_status' => 'active', 'ssl_status' => 'active'])->save();
        }
        $this->probe->certificates['shop.example.com'] = ['valid' => true, 'expires_at' => time() + 5 * 86400, 'issuer' => "Let's Encrypt", 'error' => null];
        $this->probe->pages['http://shop.example.com/'] = ['status' => 301, 'headers' => ['location' => 'https://shop.example.com/']];
        $this->probe->pages['https://shop.example.com/'] = ['status' => 200, 'headers' => ['server' => 'nginx/1.18.0']];
        $this->probe->cnames['old.example.com'] = 'shop-old.herokuapp.com';
        $this->probe->txt['_dmarc.example.com'] = ['v=DMARC1; p=none'];
        $page = "/api/app/projects/{$project->id}/security";

        $this->actingAs($owner)->getJson($page)->assertOk()->assertJsonFragment(['kind' => 'domains', 'label' => __('Domains, HTTPS and email'), 'status' => null]);
        $this->actingAs($owner)->postJson("{$page}/scans", ['kind' => 'domains'])->assertJsonRedirect($page);
        $scan = SecurityScan::query()->sole();
        $this->assertSame('done', $scan->status);

        $titles = SecurityFinding::query()->where('status', 'open')->pluck('severity', 'title')->all();
        $this->assertSame('critical', $titles['old.example.com points at shop-old.herokuapp.com, which no longer exists']);
        $this->assertSame('high', $titles['The certificate for shop.example.com expires in 5 days']);
        $this->assertSame('high', $titles['example.com doesn’t answer over HTTPS']);
        $this->assertSame('medium', $titles['shop.example.com doesn’t send Strict-Transport-Security']);
        $this->assertSame('low', $titles['shop.example.com reveals software versions (nginx/1.18.0)']);
        $this->assertSame('medium', $titles['example.com has no SPF record']);
        $this->assertSame('low', $titles['example.com’s DMARC policy only monitors (p=none)']);
        $this->assertArrayNotHasKey('shop.example.com doesn’t send HTTP visitors to HTTPS', $titles);

        $this->actingAs($owner)->getJson($page)->assertOk()->assertJsonPath('recent.0.title', 'old.example.com points at shop-old.herokuapp.com, which no longer exists')->assertJsonPath('grade', 'F');
        $this->actingAs($owner)->getJson("{$page}/findings?severity=low")->assertOk()->assertJsonFragment(['title' => 'shop.example.com reveals software versions (nginx/1.18.0)'])->assertDontSee('herokuapp');

        $version = SecurityFinding::query()->where('title', 'like', '%reveals software versions%')->sole();
        $this->actingAs($owner)->putJson("{$page}/findings/{$version->id}", ['status' => 'ignored', 'reason' => 'Behind a proxy'])->assertSuccessful();
        $this->assertSame(['ignored', 'Behind a proxy'], [$version->refresh()->status, $version->ignored_reason]);

        // Fixes: the dangling record is removed, the certificate renewed, headers and email records added.
        unset($this->probe->cnames['old.example.com']);
        $this->probe->certificates['old.example.com'] = $this->probe->certificates['example.com'] = $this->probe->certificates['shop.example.com'] = ['valid' => true, 'expires_at' => time() + 80 * 86400, 'issuer' => "Let's Encrypt", 'error' => null];
        $this->probe->pages['https://shop.example.com/'] = ['status' => 200, 'headers' => ['server' => 'nginx/1.18.0', 'strict-transport-security' => 'max-age=31536000', 'x-content-type-options' => 'nosniff', 'x-frame-options' => 'SAMEORIGIN', 'referrer-policy' => 'no-referrer']];
        $this->probe->txt['example.com'] = ['v=spf1 include:_spf.example.net -all'];
        $this->probe->txt['_dmarc.example.com'] = ['v=DMARC1; p=reject'];
        $this->actingAs($owner)->postJson("{$page}/scans", ['kind' => 'domains']);
        $this->assertFalse(SecurityFinding::query()->where('status', 'open')->where('scope', 'domain:shop.example.com')->exists());
        $this->assertSame('ignored', $version->refresh()->status, 'Ignored findings stay ignored.');
        $this->assertTrue(SecurityFinding::query()->where('status', 'resolved')->where('title', 'like', '%herokuapp%')->exists());

        $viewer = User::factory()->create();
        $this->addMember($project, $viewer, AccountRole::Viewer);
        $this->actingAs($viewer)->getJson($page)->assertOk()->assertJsonPath('canManage', false);
        $this->actingAs($viewer)->postJson("{$page}/scans", ['kind' => 'domains'])->assertForbidden();

        $this->assertSame(1, app(ScanSchedule::class)->queueDue(), 'Domains were just scanned; dependencies never were.');
        $this->assertSame(0, app(ScanSchedule::class)->queueDue());
        $this->travel(8)->days();
        $this->assertSame(2, app(ScanSchedule::class)->queueDue(), 'The free plan scans weekly.');
    }
}

/** A domain probe answering from arrays. */
final class FakeDomainProbe implements DomainProbe
{
    /**
     * Certificates by host; hosts not listed don't answer over HTTPS.
     *
     * @var array<string, array{valid: bool, expires_at: int|null, issuer: string|null, error: string|null}>
     */
    public array $certificates = [];

    /**
     * Responses by URL.
     *
     * @var array<string, array{status: int, headers: array<string, string>}>
     */
    public array $pages = [];

    /**
     * TXT records by name.
     *
     * @var array<string, list<string>>
     */
    public array $txt = [];

    /**
     * CNAME targets by name.
     *
     * @var array<string, string>
     */
    public array $cnames = [];

    /**
     * Answer the certificate listed for the host, or a failed connection.
     *
     * @param  string  $host
     * @return array{valid: bool, expires_at: int|null, issuer: string|null, error: string|null}
     */
    public function certificate(string $host): array
    {
        return $this->certificates[$host] ?? ['valid' => false, 'expires_at' => null, 'issuer' => null, 'error' => 'Connection refused'];
    }

    /**
     * Say no host accepts old TLS.
     *
     * @param  string  $host
     * @return bool
     */
    public function acceptsOldTls(string $host): bool
    {
        return false;
    }

    /**
     * Answer the response listed for the URL.
     *
     * @param  string  $url
     * @return array{status: int, headers: array<string, string>}|null
     */
    public function fetch(string $url): ?array
    {
        return $this->pages[$url] ?? null;
    }

    /**
     * Answer the TXT records listed for the name.
     *
     * @param  string  $name
     * @return list<string>
     */
    public function txt(string $name): array
    {
        return $this->txt[$name] ?? [];
    }

    /**
     * Answer the CNAME listed for the name.
     *
     * @param  string  $name
     * @return string|null
     */
    public function cname(string $name): ?string
    {
        return $this->cnames[$name] ?? null;
    }

    /**
     * Say nothing resolves, so every listed CNAME dangles.
     *
     * @param  string  $name
     * @return bool
     */
    public function resolves(string $name): bool
    {
        return false;
    }
}
