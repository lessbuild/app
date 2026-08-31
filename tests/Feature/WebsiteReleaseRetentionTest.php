<?php

namespace Tests\Feature;

use App\Jobs\Web\AddWebsiteJob;
use App\Models\Build;
use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Scripts\Repository\PurgeOldReleasesScript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WebsiteReleaseRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_websites_keep_five_releases_by_default(): void
    {
        Queue::fake();
        [$owner, $server] = $this->infrastructure();

        $this->actingAs($owner)->post(route('websites.store'), $this->payload($server))
            ->assertRedirect();

        $website = Website::query()->sole();
        $this->assertSame(5, $website->release_retention);
        Queue::assertPushed(AddWebsiteJob::class);

        $this->actingAs($owner)->get(route('websites.show', $website))
            ->assertSuccessful()
            ->assertSee('Retained releases')
            ->assertSee('5');
        $this->actingAs($owner)->get(route('websites.edit', $website))
            ->assertSuccessful()
            ->assertSee('Keep between 2 and 20 releases')
            ->assertSee('value="5"', false);
    }

    public function test_custom_retention_controls_remote_release_pruning(): void
    {
        Queue::fake();
        [$owner, $server] = $this->infrastructure();

        $this->actingAs($owner)->post(route('websites.store'), [
            ...$this->payload($server),
            'release_retention' => '12',
        ])->assertRedirect();

        $website = Website::query()->sole();
        $this->assertSame(12, $website->release_retention);
        $script = (new PurgeOldReleasesScript)->script(8, $this->build($owner, $website));
        $this->assertStringContainsString('tail -n +13', $script);
        $this->assertStringNotContainsString('tail -n +6', $script);
        $this->assertStringContainsString('rm -rf -- "$release"', $script);
        $this->assertShellSyntax($script);
    }

    public function test_retention_must_leave_a_safe_rollback_window_and_remain_bounded(): void
    {
        Queue::fake();
        [$owner, $server] = $this->infrastructure();

        foreach (['1', '21', '2.5', 'many'] as $retention) {
            $this->actingAs($owner)->post(route('websites.store'), [
                ...$this->payload($server),
                'release_retention' => $retention,
            ])->assertSessionHasErrors('release_retention');
        }

        $this->assertDatabaseCount('websites', 0);
        Queue::assertNothingPushed();
    }

    public function test_omitted_retention_does_not_reset_an_existing_website(): void
    {
        Queue::fake();
        [$owner, $server] = $this->infrastructure();
        $website = $owner->websites()->create([
            ...$this->payload($server),
            'release_retention' => 9,
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $token = $website->provisioning_token;

        $this->actingAs($owner)->patch(route('websites.update', $website), $this->payload($server))
            ->assertRedirect(route('websites.show', $website));

        $website->refresh();
        $this->assertSame(9, $website->release_retention);
        $this->assertSame(Website::STATUS_ACTIVE, $website->provisioning_status);
        $this->assertSame($token, $website->provisioning_token);
        Queue::assertNothingPushed();
    }

    public function test_retention_changes_apply_without_reprovisioning_the_website(): void
    {
        Queue::fake();
        [$owner, $server] = $this->infrastructure();
        $website = $owner->websites()->create([
            ...$this->payload($server),
            'release_retention' => 5,
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $website->logs()->create([
            'type' => Website::PROVISIONING_LOG_TYPE,
            'log' => 'Existing provisioning output',
        ]);
        $token = $website->provisioning_token;

        $this->actingAs($owner)->patch(route('websites.update', $website), [
            ...$this->payload($server),
            'release_retention' => '12',
        ])->assertRedirect(route('websites.show', $website));

        $website->refresh();
        $this->assertSame(12, $website->release_retention);
        $this->assertSame(Website::STATUS_ACTIVE, $website->provisioning_status);
        $this->assertSame($token, $website->provisioning_token);
        $this->assertSame('Existing provisioning output', $website->logs()->sole()->log);
        Queue::assertNothingPushed();
    }

    /** @return array{User, Server} */
    private function infrastructure(): array
    {
        $owner = User::factory()->create();
        $server = $owner->servers()->create([
            'name' => 'Production',
            'type' => ServerTypeEnum::app,
            'provisioning_status' => Server::STATUS_ACTIVE,
            'mysql_root_password' => 'mysql-root-secret',
        ]);

        return [$owner, $server];
    }

    /** @return array<string, mixed> */
    private function payload(Server $server): array
    {
        return [
            'server_id' => $server->id,
            'name' => 'Application',
            'url' => 'app.example.com',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'health_check_enabled' => '0',
            'health_check_path' => '/',
        ];
    }

    private function build(User $owner, Website $website): Build
    {
        $provider = $owner->providers()->create([
            'name' => 'GitHub',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'source-secret',
            'description' => 'Source provider',
        ]);
        $repository = $owner->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => 'Application repository',
            'url' => 'github.com/example/application.git',
            'branch' => 'main',
            'description' => 'Application source',
        ]);

        return $repository->builds()->create(['status' => Build::STATUS_RUNNING]);
    }

    private function assertShellSyntax(string $script): void
    {
        $syntax = new Process(['bash', '-n']);
        $syntax->setInput($script);
        $syntax->run();
        $this->assertTrue($syntax->isSuccessful(), $syntax->getErrorOutput());
    }
}
