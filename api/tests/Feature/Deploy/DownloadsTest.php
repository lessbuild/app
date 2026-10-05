<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Enums\AccountRole;
use App\Enums\ProviderType;
use App\Models\Build;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\RepositoryWebhookDelivery;
use App\Models\Server;
use App\Models\ServerLogSnapshot;
use App\Models\SignInEvent;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class DownloadsTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private Repository $repository;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $this->project = Project::factory()->withServices(['deploy', 'infrastructure'])->create();
        $this->owner = $this->ownerOf($this->project);
        $github = Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $this->project->account_id]);
        $this->server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $this->project->account_id])->id, 'name' => 'web-1']);
        $website = Website::factory()->create(['server_id' => $this->server->id]);
        $this->repository = Repository::factory()->create(['project_id' => $this->project->id, 'website_id' => $website->id, 'provider_id' => $github->id]);
    }

    /**
     * A deploy's log downloads as text, its team note can be saved by people who can deploy, and the repository's
     * deploys and push deliveries download as CSV.
     */
    public function test_deploy_logs_notes_and_exports(): void
    {
        $build = Build::factory()->succeeded()->create(['repository_id' => $this->repository->id, 'log' => "Cloning\nDone", 'commit_message' => "=SUM(A1)\nmore"]);
        RepositoryWebhookDelivery::query()->forceCreate(['repository_id' => $this->repository->id, 'delivery_id' => 'abc', 'status' => 'queued', 'changed_paths' => ['a.php', 'b.php'], 'build_id' => $build->id]);
        $base = "/api/app/projects/{$this->project->id}/deploy";

        $log = $this->actingAs($this->owner)->get("{$base}/builds/{$build->id}/log")->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="deploy-'.$build->id.'.log"');
        $this->assertSame("Cloning\nDone", $log->getContent());

        $this->actingAs($this->owner)->putJson("{$base}/builds/{$build->id}/note", ['note' => '  Hotfix for checkout  '])->assertOk();
        $this->assertSame('Hotfix for checkout', $build->refresh()->operator_note);
        $this->actingAs($this->owner)->getJson("{$base}/builds/{$build->id}")->assertJsonPath('build.note', 'Hotfix for checkout');
        $viewer = User::factory()->create();
        $this->project->account->memberships()->forceCreate(['user_id' => $viewer->id, 'role' => AccountRole::Viewer]);
        $this->actingAs($viewer)->putJson("{$base}/builds/{$build->id}/note", ['note' => 'no'])->assertForbidden();

        $csv = $this->actingAs($this->owner)->get("{$base}/repositories/{$this->repository->id}/builds.csv")->assertOk()->streamedContent();
        $this->assertStringContainsString('Hotfix for checkout', $csv);
        $this->assertStringContainsString("'=SUM(A1)", $csv);
        $this->assertStringNotContainsString('Cloning', $csv);
        $deliveries = $this->actingAs($this->owner)->get("{$base}/repositories/{$this->repository->id}/webhook-deliveries.csv")->assertOk()->streamedContent();
        $this->assertStringContainsString('abc,queued', $deliveries);
    }

    /**
     * A server log that's been fetched downloads as text; one that hasn't is a 404.
     */
    public function test_server_logs_download(): void
    {
        $base = "/api/app/projects/{$this->project->id}/infrastructure/servers/{$this->server->id}/logs";
        $this->actingAs($this->owner)->get("{$base}/syslog")->assertNotFound();
        ServerLogSnapshot::query()->forceCreate(['server_id' => $this->server->id, 'type' => 'syslog', 'status' => 'ready', 'log' => 'kernel: hello']);
        $this->assertSame('kernel: hello', $this->actingAs($this->owner)->get("{$base}/syslog")->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="web-1-syslog.log"')->getContent());
    }

    /**
     * The sign-in history downloads as CSV and can be cleared after confirming the password.
     */
    public function test_sign_in_history_exports_and_clears(): void
    {
        SignInEvent::query()->forceCreate(['id' => (string) Str::ulid(), 'user_id' => $this->owner->id, 'succeeded' => true, 'two_factor' => false, 'ip_address' => '203.0.113.5', 'user_agent' => 'Firefox', 'created_at' => now()]);
        $csv = $this->actingAs($this->owner)->get('/api/app/settings/sign-ins.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('203.0.113.5', $csv);

        $this->actingAs($this->owner)->deleteJson('/api/app/settings/sign-ins')->assertStatus(423);
        $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->deleteJson('/api/app/settings/sign-ins')->assertOk();
        $this->assertSame(0, SignInEvent::query()->where('user_id', $this->owner->id)->count());
    }
}
