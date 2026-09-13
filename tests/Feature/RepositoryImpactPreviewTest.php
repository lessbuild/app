<?php

namespace Tests\Feature;

use App\Data\RepositoryChangeImpact;
use App\Data\RepositoryImpactPreview;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RepositoryImpactPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_member_can_preview_enabled_targets_without_side_effects(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        [$provider, $website] = $this->infrastructure($owner, 'Owner');

        $affected = $this->repository($owner, $provider, $website, 'Storefront', true, [
            'auto_deploy_include_paths' => ['apps/storefront/**'],
            'deployment_root' => 'apps/storefront',
        ]);
        $unaffected = $this->repository($owner, $provider, $website, 'Admin', true, [
            'auto_deploy_include_paths' => ['apps/admin/**'],
            'deployment_root' => 'apps/admin',
        ]);
        $disabled = $this->repository($owner, $provider, $website, 'Disabled', false, [
            'auto_deploy_include_paths' => ['apps/storefront/**'],
        ]);

        $foreign = User::factory()->create();
        [$foreignProvider, $foreignWebsite] = $this->infrastructure($foreign, 'Foreign');
        $this->repository($foreign, $foreignProvider, $foreignWebsite, 'Private', true, [
            'auto_deploy_include_paths' => ['apps/storefront/**'],
        ]);

        $response = $this->actingAs($owner)->get(route('repositories.impact-preview', [
            'changed_paths' => "apps/storefront/app.php\npackages/shared/src/Client.php",
        ]));

        $response->assertSuccessful()
            ->assertViewHas('preview', function (RepositoryImpactPreview $preview) use ($affected, $unaffected): bool {
                $targets = collect($preview->targets);

                return $preview->changedPaths === ['apps/storefront/app.php', 'packages/shared/src/Client.php']
                    && $preview->counts === [
                        'affected' => 1,
                        'unaffected' => 1,
                        'unknown' => 0,
                    ]
                    && $targets->pluck('repository.id')->all() === [$unaffected->id, $affected->id]
                    && $targets->firstWhere('repository.id', $affected->id)->impact->status === RepositoryChangeImpact::AFFECTED
                    && $targets->firstWhere('repository.id', $unaffected->id)->impact->status === RepositoryChangeImpact::UNAFFECTED
                    && $targets->every(fn ($target): bool => $target->repository->relationLoaded('website'));
            })
            ->assertSee('Storefront')
            ->assertSee('Admin')
            ->assertDontSee('Disabled')
            ->assertDontSee('Private')
            ->assertSee('Affected')
            ->assertSee('Unaffected')
            ->assertSee('apps/storefront');

        $this->assertDatabaseCount('builds', 0);
        $this->assertDatabaseCount('repository_webhook_deliveries', 0);
        Queue::assertNothingPushed();
    }

    public function test_unavailable_changed_paths_show_unknown_for_every_enabled_target(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        [$provider, $website] = $this->infrastructure($owner, 'Owner');
        $this->repository($owner, $provider, $website, 'Storefront', true, [
            'auto_deploy_include_paths' => ['apps/storefront/**'],
        ]);
        $this->repository($owner, $provider, $website, 'Admin', true, [
            'auto_deploy_include_paths' => ['apps/admin/**'],
        ]);

        $response = $this->actingAs($owner)->get(route('repositories.impact-preview', [
            'changed_paths_unavailable' => '1',
        ]));

        $response->assertSuccessful()
            ->assertViewHas('preview', function (RepositoryImpactPreview $preview): bool {
                return $preview->changedPaths === null
                    && $preview->counts === [
                        'affected' => 0,
                        'unaffected' => 0,
                        'unknown' => 2,
                    ]
                    && collect($preview->targets)->every(fn ($target): bool => $target->impact->status === RepositoryChangeImpact::UNKNOWN);
            })
            ->assertSee('Changed paths were unavailable')
            ->assertSee('Unknown')
            ->assertSee('deployable');

        $this->assertDatabaseCount('builds', 0);
        Queue::assertNothingPushed();
    }

    public function test_workspace_policy_runs_before_malformed_preview_validation(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $outsider->update(['current_organization_id' => $owner->current_organization_id]);
        $outsider->refresh();

        $this->actingAs($outsider)->get(route('repositories.impact-preview', [
            'changed_paths' => '../secrets.env',
        ]))->assertForbidden();
    }

    public function test_invalid_preview_paths_are_rejected_without_a_preview_or_side_effects(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        [$provider, $website] = $this->infrastructure($owner, 'Owner');
        $this->repository($owner, $provider, $website, 'Storefront', true, [
            'auto_deploy_include_paths' => ['apps/storefront/**'],
        ]);

        $this->actingAs($owner)->get(route('repositories.impact-preview', [
            'changed_paths' => "apps/storefront/app.php\n../secrets.env",
        ]))->assertSessionHasErrors('changed_paths');

        $this->assertDatabaseCount('builds', 0);
        Queue::assertNothingPushed();
    }

    /** @return array{Provider, Website} */
    private function infrastructure(User $user, string $prefix): array
    {
        $provider = $user->providers()->create([
            'name' => "{$prefix} GitHub",
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'provider-token',
            'description' => 'Source provider',
        ]);
        $server = $user->servers()->create([
            'name' => "{$prefix} server",
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $user->websites()->create([
            'server_id' => $server->id,
            'name' => "{$prefix} website",
            'url' => str($prefix)->slug().'.example.com',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);

        return [$provider, $website];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function repository(
        User $user,
        Provider $provider,
        Website $website,
        string $name,
        bool $webhookEnabled,
        array $overrides = [],
    ): Repository {
        return $user->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => $name,
            'url' => 'github.com/example/'.str($name)->slug().'.git',
            'branch' => 'main',
            'description' => 'Repository',
            'build_commands' => 'repository-build-command-secret',
            'webhook_enabled' => $webhookEnabled,
            ...$overrides,
        ]);
    }
}
