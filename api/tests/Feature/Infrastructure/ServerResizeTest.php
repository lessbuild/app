<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Enums\AccountRole;
use App\Enums\AuditAction;
use App\Jobs\Infrastructure\RunServerResize;
use App\Models\AuditEntry;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Services\Infrastructure\ServerProviderResolver;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;
use Throwable;

final class ServerResizeTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private Provider $provider;

    private string $base;

    /**
     * A project with a cloud provider whose sizes include the one we'll resize to.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $this->withoutMiddleware(RequirePassword::class);
        $this->project = Project::factory()->withServices(['infrastructure'])->create();
        $this->owner = $this->ownerOf($this->project);
        $this->provider = Provider::factory()->create(['account_id' => $this->project->account_id]);
        $this->cloud->sizes = [['slug' => 's-1vcpu-1gb'], ['slug' => 's-1vcpu-2gb']];
        $this->base = "/api/app/projects/{$this->project->id}/infrastructure/servers";
    }

    /**
     * Make an active cloud server on the fake provider.
     *
     * @param  string  $size
     * @return Server
     */
    private function cloudServer(string $size = 's-1vcpu-1gb'): Server
    {
        return Server::factory()->create([
            'account_id' => $this->project->account_id, 'provider_id' => $this->provider->id,
            'identifier' => 'cloud-9', 'size' => $size, 'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
    }

    /**
     * Resizing queues the work, marks the server resizing, and the job powers it off, resizes and powers it on.
     *
     * @return void
     */
    public function test_resizing_a_cloud_server_runs_the_steps_in_order(): void
    {
        Queue::fake();
        $server = $this->cloudServer();

        $this->actingAs($this->owner)->postJson("{$this->base}/{$server->id}/resize", ['size' => 's-1vcpu-2gb', 'resize_disk' => true])->assertOk();
        $this->assertSame(Server::STATUS_RESIZING, $server->refresh()->provisioning_status);
        Queue::assertPushed(RunServerResize::class, fn (RunServerResize $job): bool => $job->serverId === $server->id && $job->size === 's-1vcpu-2gb' && $job->resizeDisk === true);
        $this->assertSame(1, AuditEntry::query()->where('action', AuditAction::ServerResized->value)->count());

        (new RunServerResize($server->id, 's-1vcpu-2gb', true))->handle(app(ServerProviderResolver::class));
        $this->assertSame([['off', 'cloud-9'], ['resize', 'cloud-9', 's-1vcpu-2gb', true], ['on', 'cloud-9']], $this->cloud->resizeSteps);
        $this->assertSame(Server::STATUS_ACTIVE, $server->refresh()->provisioning_status);
    }

    /**
     * A failed resize still leaves the server powered on and active, not stuck off or resizing.
     *
     * @return void
     */
    public function test_a_failed_resize_powers_the_server_back_on(): void
    {
        $server = $this->cloudServer();
        $server->forceFill(['provisioning_status' => Server::STATUS_RESIZING])->save();
        $this->cloud->failResize = true;

        try {
            (new RunServerResize($server->id, 's-1vcpu-2gb', false))->handle(app(ServerProviderResolver::class));
            $this->fail('The resize should have thrown.');
        } catch (Throwable) {
            // expected
        }
        $this->assertContains(['on', 'cloud-9'], $this->cloud->resizeSteps, 'It powers the server back on.');
        $this->assertSame(Server::STATUS_ACTIVE, $server->refresh()->provisioning_status);
    }

    /**
     * The size must be a different one from the provider's list, and only a manager may resize.
     *
     * @return void
     */
    public function test_resize_is_validated_and_authorised(): void
    {
        $server = $this->cloudServer('s-1vcpu-1gb');
        $this->actingAs($this->owner)->postJson("{$this->base}/{$server->id}/resize", ['size' => 's-1vcpu-1gb'])->assertJsonValidationErrors('size');
        $this->actingAs($this->owner)->postJson("{$this->base}/{$server->id}/resize", ['size' => 'not-a-size'])->assertJsonValidationErrors('size');

        $other = Project::factory()->create(['account_id' => $this->project->account_id]);
        $member = User::factory()->create();
        $this->addMember($this->project, $member, AccountRole::Member)->forceFill(['project_ids' => [$other->id]])->save();
        $this->assertContains($this->actingAs($member)->postJson("{$this->base}/{$server->id}/resize", ['size' => 's-1vcpu-2gb'])->status(), [403, 404]);
    }
}
