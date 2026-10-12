<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Jobs\Infrastructure\ProvisionWebsite;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\Website;
use App\Models\WebsiteDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class WebsiteDomainRemovalTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * The primary domain can't be removed while it's the website's only domain; with an alias it can, the alias becomes
     * the main domain and the website is set up again under it. A domain's status can be checked straight away, with a
     * reason someone can act on.
     *
     * @return void
     */
    public function test_the_primary_domain_can_be_removed_once_another_can_take_over_and_domains_can_be_checked(): void
    {
        $this->fakeInfrastructure();
        Queue::fake();
        $project = Project::factory()->withServices(['infrastructure'])->create();
        $owner = $this->ownerOf($project);
        $server = Server::factory()->create(['account_id' => $project->account_id, 'public_ip' => '203.0.113.10', 'provider_id' => Provider::factory()->create(['account_id' => $project->account_id])->id]);
        $website = Website::factory()->create(['server_id' => $server->id, 'url' => 'old.invalid']);
        $website->forceFill(['provisioning_status' => Website::STATUS_ACTIVE])->save();
        $primary = $website->domains()->where('type', 'primary')->sole();
        $base = "/api/app/projects/{$project->id}/infrastructure/websites/{$website->id}/domains";

        $this->actingAs($owner)->deleteJson("{$base}/{$primary->id}")->assertUnprocessable()->assertJsonValidationErrors('domain');

        $this->actingAs($owner)->postJson("{$base}/{$primary->id}/check")->assertOk()->assertJsonPath('message', 'old.invalid has no A record yet. Add one pointing at 203.0.113.10 at your DNS provider.');
        $this->assertSame('pending', $primary->refresh()->dns_status);
        $this->assertNotNull($primary->last_checked_at);

        $alias = new WebsiteDomain;
        $alias->forceFill(['website_id' => $website->id, 'hostname' => 'new.invalid', 'type' => 'alias', 'dns_status' => 'pending', 'ssl_status' => 'pending'])->save();
        $this->actingAs($owner)->deleteJson("{$base}/{$primary->id}")->assertOk()->assertJsonPath('message', 'Domain removed. new.invalid is the website’s main domain now, and the website is being set up again under it.');

        $this->assertNull(WebsiteDomain::query()->find($primary->id));
        $this->assertSame('primary', $alias->refresh()->type);
        $this->assertSame('new.invalid', $website->refresh()->url);
        $this->assertSame(Website::STATUS_QUEUED, $website->provisioning_status);
        Queue::assertPushed(ProvisionWebsite::class);
    }
}
