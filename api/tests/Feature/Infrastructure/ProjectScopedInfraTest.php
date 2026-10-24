<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\Website;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class ProjectScopedInfraTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * A project's Infrastructure pages show that project's own servers and websites plus anything not yet assigned
     * to any project — not another project's resources. The account owner is not exempt (this is about tidiness,
     * not permission).
     *
     * @return void
     */
    public function test_infrastructure_lists_are_scoped_to_the_project(): void
    {
        $this->fakeInfrastructure();
        $this->withoutMiddleware(RequirePassword::class);
        $vacenly = Project::factory()->withServices(['infrastructure', 'deploy'])->create(['name' => 'Vacenly']);
        $payeio = Project::factory()->withServices(['infrastructure', 'deploy'])->create(['account_id' => $vacenly->account_id, 'name' => 'Payeio']);
        $owner = $this->ownerOf($vacenly);
        $provider = Provider::factory()->create(['account_id' => $vacenly->account_id]);

        $vacenlyServer = Server::factory()->create(['account_id' => $vacenly->account_id, 'provider_id' => $provider->id, 'name' => 'vacenly-box']);
        $payeioServer = Server::factory()->create(['account_id' => $vacenly->account_id, 'provider_id' => $provider->id, 'name' => 'payeio-box']);
        $freshServer = Server::factory()->create(['account_id' => $vacenly->account_id, 'provider_id' => $provider->id, 'name' => 'unassigned-box']);

        $vacenlyWeb = Website::factory()->create(['account_id' => $vacenly->account_id, 'server_id' => $vacenlyServer->id, 'environment_id' => $vacenly->environments()->firstOrFail()->id, 'url' => 'vacenly.com', 'deployment_slug' => 'vacenly']);
        $payeioWeb = Website::factory()->create(['account_id' => $vacenly->account_id, 'server_id' => $payeioServer->id, 'environment_id' => $payeio->environments()->firstOrFail()->id, 'url' => 'payeio.com', 'deployment_slug' => 'payeio']);
        $unassignedWeb = Website::factory()->create(['account_id' => $vacenly->account_id, 'server_id' => $freshServer->id, 'environment_id' => null, 'url' => 'floating.com', 'deployment_slug' => 'floating']);

        // On Vacenly: its own website + the unassigned one; not Payeio's.
        $urls = fn (string $project): array => array_column($this->actingAs($owner)->getJson("/api/app/projects/{$project}/infrastructure/websites")->assertOk()->json('websites'), 'url');
        $this->assertEqualsCanonicalizing(['vacenly.com', 'floating.com'], $urls($vacenly->id));
        $this->assertEqualsCanonicalizing(['payeio.com', 'floating.com'], $urls($payeio->id));

        // Servers scope the same way; the unassigned-but-hosting case: floating.com is on $freshServer, so $freshServer
        // hosts an unassigned website — it shows on both. A server hosting only another project's site does not.
        $servers = fn (string $project): array => array_column($this->actingAs($owner)->getJson("/api/app/projects/{$project}/infrastructure/servers")->assertOk()->json('servers'), 'name');
        $this->assertContains('vacenly-box', $servers($vacenly->id));
        $this->assertNotContains('payeio-box', $servers($vacenly->id));
        $this->assertContains('payeio-box', $servers($payeio->id));
        $this->assertNotContains('vacenly-box', $servers($payeio->id));

        // unassigned-box hosts only floating.com, which is itself unassigned — so, like that website, the server shows
        // on every project rather than vanishing.
        $this->assertContains('unassigned-box', $servers($vacenly->id));
        $this->assertContains('unassigned-box', $servers($payeio->id));

        // A server hosting nothing at all is unassigned too and shows everywhere.
        Server::factory()->create(['account_id' => $vacenly->account_id, 'provider_id' => $provider->id, 'name' => 'empty-box']);
        $this->assertContains('empty-box', $servers($vacenly->id));
        $this->assertContains('empty-box', $servers($payeio->id));
    }
}
