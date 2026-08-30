<?php

namespace Tests\Feature;

use App\Jobs\Web\AddWebsiteJob;
use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\ProvisioningCallbackUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebsiteProvisioningRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_retry_failed_provisioning_with_a_fresh_attempt(): void
    {
        Queue::fake();
        [$user, $website] = $this->resources();
        $oldToken = $website->provisioning_token;
        $oldCallback = ProvisioningCallbackUrl::websiteStatus($website);
        $oldJob = new AddWebsiteJob($website);

        $this->actingAs($user)->get(route('websites.show', $website))
            ->assertSuccessful()
            ->assertSee('Retry provisioning');

        $this->actingAs($user)->post(route('websites.provisioning.retry', $website))
            ->assertRedirect()
            ->assertSessionHas('success', 'Website provisioning retry queued.');

        $website->refresh();
        $this->assertSame(Website::STATUS_QUEUED, $website->provisioning_status);
        $this->assertNotSame($oldToken, $website->provisioning_token);
        $this->assertSame(0, $website->setup_stage);
        $this->assertNull($website->provisioning_error);
        $this->assertNull($website->provisioned_at);
        $this->assertNotNull($website->previous_server_id);
        Queue::assertPushed(AddWebsiteJob::class, fn (AddWebsiteJob $job): bool => $job->website->is($website)
            && $job->attemptToken === $website->provisioning_token);

        $oldJob->handle();
        $this->post($oldCallback, ['status' => 3])->assertNoContent();
        $this->assertSame(Website::STATUS_QUEUED, $website->fresh()->provisioning_status);
    }

    public function test_retry_is_atomic_and_does_not_queue_duplicate_attempts(): void
    {
        Queue::fake();
        [$user, $website] = $this->resources();

        $this->actingAs($user)->post(route('websites.provisioning.retry', $website))
            ->assertSessionHas('success');
        $this->actingAs($user)->post(route('websites.provisioning.retry', $website->fresh()))
            ->assertSessionHas('info', 'Website provisioning is no longer in a failed state.');

        Queue::assertPushedTimes(AddWebsiteJob::class, 1);
    }

    public function test_retry_requires_an_eligible_target_server(): void
    {
        Queue::fake();
        [$user, $website] = $this->resources();
        $website->server->update(['provisioning_status' => Server::STATUS_FAILED]);

        $this->actingAs($user)->post(route('websites.provisioning.retry', $website))
            ->assertSessionHasErrors([
                'retry' => 'The target server must be active and ready before provisioning can be retried.',
            ]);

        $this->assertSame(Website::STATUS_FAILED, $website->fresh()->provisioning_status);
        Queue::assertNotPushed(AddWebsiteJob::class);
    }

    public function test_other_users_cannot_retry_website_provisioning(): void
    {
        Queue::fake();
        [, $website] = $this->resources();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->post(route('websites.provisioning.retry', $website))
            ->assertForbidden();

        $this->assertSame(Website::STATUS_FAILED, $website->fresh()->provisioning_status);
        Queue::assertNotPushed(AddWebsiteJob::class);
    }

    private function resources(): array
    {
        $user = User::factory()->create();
        $source = $user->servers()->create([
            'name' => 'Source',
            'type' => ServerTypeEnum::app,
            'provisioning_status' => Server::STATUS_ACTIVE,
            'mysql_root_password' => 'source-root-secret',
        ]);
        $target = $user->servers()->create([
            'name' => 'Target',
            'type' => ServerTypeEnum::app,
            'provisioning_status' => Server::STATUS_ACTIVE,
            'mysql_root_password' => 'target-root-secret',
        ]);
        $website = $user->websites()->create([
            'server_id' => $target->id,
            'previous_server_id' => $source->id,
            'name' => 'Customer Portal',
            'description' => 'Customer portal',
            'environment' => 'APP_ENV=production',
            'url' => 'portal.example.com',
            'database_password' => 'database-secret',
            'setup_stage' => 2,
            'provisioning_status' => Website::STATUS_FAILED,
            'provisioning_error' => 'Caddy reload failed',
            'provisioned_at' => now()->subMinute(),
        ]);

        return [$user, $website];
    }
}
