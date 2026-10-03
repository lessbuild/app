<?php

declare(strict_types=1);

namespace Tests\Feature\StatusPages;

use App\Contracts\DnsResolver;
use App\Models\Project;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeDnsResolver;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class StatusPageDomainTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check that a status page gets a custom domain after its TXT record is found, is then served there (and only
     * the page is), and that Caddy is told which domains may get certificates.
     *
     * @return void
     */
    public function test_a_status_page_is_served_on_its_verified_custom_domain(): void
    {
        $dns = new FakeDnsResolver;
        $this->app->instance(DnsResolver::class, $dns);
        $project = Project::factory()->withServices(['monitoring'])->create();
        $owner = $this->ownerOf($project);
        $page = StatusPage::factory()->create(['account_id' => $project->account_id, 'name' => 'Acme status', 'slug' => 'acme', 'published' => true]);
        // Absolute URLs throughout: after a request to another host, the test client resolves relative ones against it.
        $app = rtrim((string) config('app.url'), '/');
        $base = "{$app}/projects/{$project->id}/monitoring/status-pages/{$page->id}";

        $this->actingAs($owner)->put("{$base}/domain", ['custom_domain' => 'not a domain'])->assertSessionHasErrors('custom_domain');
        $this->actingAs($owner)->put("{$base}/domain", ['custom_domain' => 'https://Status.Acme.test/'])->assertRedirect($base);
        $page->refresh();
        $this->assertSame('status.acme.test', $page->custom_domain);
        $this->actingAs($owner)->get($base)->assertOk()->assertSee('_buildpusher.status.acme.test')->assertSee((string) $page->domainRecordValue())->assertSee('Check DNS');

        // Before verification the domain is neither served nor given a certificate.
        $this->get('http://status.acme.test/')->assertDontSee('Acme status');
        $this->get($app.'/internal/tls/status-domain?domain=status.acme.test')->assertNotFound();
        $this->actingAs($owner)->post("{$base}/domain/verify")->assertSessionHasErrors('custom_domain');

        $dns->records['_buildpusher.status.acme.test'] = [(string) $page->domainRecordValue()];
        $this->actingAs($owner)->post("{$base}/domain/verify")->assertSessionHasNoErrors();
        $this->assertNotNull($page->refresh()->custom_domain_verified_at);
        $this->get($app.'/internal/tls/status-domain?domain=status.acme.test')->assertOk();
        $this->get($app.'/internal/tls/status-domain?domain=elsewhere.test')->assertNotFound();

        auth()->logout();
        $this->get('http://status.acme.test/')->assertOk()->assertSee('Acme status')->assertSee('<link rel="canonical" href="https://status.acme.test/">', false);
        $this->get('http://status.acme.test/report.json')->assertOk()->assertJsonPath('name', 'Acme status');
        $this->get('http://status.acme.test/login')->assertNotFound();
        $this->get('http://status.acme.test/dashboard')->assertNotFound();

        // Another page can't claim the same domain, and the platform's own host can't be used.
        $other = StatusPage::factory()->create(['account_id' => $project->account_id, 'slug' => 'other']);
        $this->actingAs($owner)->put("{$app}/projects/{$project->id}/monitoring/status-pages/{$other->id}/domain", ['custom_domain' => 'status.acme.test'])->assertSessionHasErrors('custom_domain');
        $this->actingAs($owner)->put("{$app}/projects/{$project->id}/monitoring/status-pages/{$other->id}/domain", ['custom_domain' => 'status.'.parse_url((string) config('app.url'), PHP_URL_HOST).'.test'])->assertSessionHasNoErrors();

        // Removing the domain stops it being served.
        $this->actingAs($owner)->put("{$base}/domain", ['custom_domain' => ''])->assertRedirect($base);
        $this->assertNull($page->refresh()->custom_domain);
        $this->get($app.'/internal/tls/status-domain?domain=status.acme.test')->assertNotFound();
    }

    /**
     * Check that people who can't edit the page can't set its domain.
     *
     * @return void
     */
    public function test_only_people_who_can_edit_the_page_set_its_domain(): void
    {
        $project = Project::factory()->withServices(['monitoring'])->create();
        $page = StatusPage::factory()->create(['account_id' => $project->account_id]);
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->put("/projects/{$project->id}/monitoring/status-pages/{$page->id}/domain", ['custom_domain' => 'status.acme.test'])->assertNotFound();
        $this->assertNull($page->refresh()->custom_domain);
    }
}
