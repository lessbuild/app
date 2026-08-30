<?php

namespace Tests\Feature;

use App\Jobs\Web\DeleteWebsiteFromCaddyJob;
use App\Models\Build;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\ManagedSsh;
use App\Services\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WebsiteDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_remote_cleanup_finalizes_the_complete_resource_tree(): void
    {
        Queue::fake();
        [$user, $website, $repository, $build] = $this->resources();

        $this->actingAs($user)->delete(route('websites.destroy', $website))
            ->assertRedirect(route('websites.index'))
            ->assertSessionHas('success', 'Website deletion queued.');

        $this->assertSoftDeleted($website);
        $this->assertDatabaseHas('repositories', ['id' => $repository->id, 'deleted_at' => null]);

        (new DeleteWebsiteFromCaddyJob($website->id))->handle($this->runner(successful: true));

        $this->assertDatabaseMissing('websites', ['id' => $website->id]);
        $this->assertDatabaseMissing('repositories', ['id' => $repository->id]);
        $this->assertDatabaseMissing('builds', ['id' => $build->id]);
    }

    public function test_failed_remote_cleanup_restores_the_website_and_preserves_resources(): void
    {
        Queue::fake();
        [$user, $website, $repository, $build] = $this->resources();
        $this->actingAs($user)->delete(route('websites.destroy', $website));
        $job = new DeleteWebsiteFromCaddyJob($website->id);

        try {
            $job->handle($this->runner(successful: false));
            $this->fail('Expected remote cleanup to fail.');
        } catch (RuntimeException $exception) {
            $job->failed($exception);
        }

        $website->refresh();
        $this->assertNull($website->deleted_at);
        $this->assertSame(Website::STATUS_FAILED, $website->provisioning_status);
        $this->assertSame('Unable to remove the website from its server: Permission denied', $website->provisioning_error);
        $this->assertDatabaseHas('repositories', ['id' => $repository->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('builds', ['id' => $build->id]);
    }

    public function test_cleanup_is_idempotent_when_server_deletion_already_removed_the_website(): void
    {
        Queue::fake();
        [, $website] = $this->resources();
        $websiteId = $website->id;
        Website::withoutEvents(fn () => $website->forceDelete());
        $runner = Mockery::mock(Runner::class);
        $runner->shouldNotReceive('server');

        (new DeleteWebsiteFromCaddyJob($websiteId))->handle($runner);

        $this->assertDatabaseMissing('websites', ['id' => $websiteId]);
    }

    private function runner(bool $successful): Runner
    {
        $result = Mockery::mock(Process::class);
        $result->shouldReceive('isSuccessful')->once()->andReturn($successful);
        if (! $successful) {
            $result->shouldReceive('getErrorOutput')->once()->andReturn('Permission denied');
        }

        $ssh = Mockery::mock(ManagedSsh::class);
        $ssh->shouldReceive('execute')->once()->andReturn($result);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->andReturnSelf();
        $runner->shouldReceive('create')->once()->andReturn($ssh);

        return $runner;
    }

    private function resources(): array
    {
        $user = User::factory()->create();
        $server = $user->servers()->create([
            'name' => 'Production',
            'public_ip' => '192.0.2.10',
            'ssh_private_key' => 'private-key',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $user->websites()->create([
            'server_id' => $server->id,
            'name' => 'Application',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'url' => 'app.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $provider = $user->providers()->create([
            'name' => 'GitHub',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'source-secret',
            'description' => 'Source provider',
        ]);
        $repository = $user->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => 'Application',
            'url' => 'github.com/example/application.git',
            'branch' => 'main',
            'description' => 'Application source',
        ]);
        $build = $repository->builds()->create(['status' => Build::STATUS_SUCCEEDED]);

        return [$user, $website, $repository, $build];
    }
}
