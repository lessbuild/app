<?php

namespace Tests\Feature;

use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\ObservabilityInvestigationView;
use App\Modules\Deployer\Models\Provider;
use App\Modules\Deployer\Models\Repository;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ObservabilityInvestigationViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_save_a_named_view_and_open_it_as_the_canonical_context(): void
    {
        [$owner, $environment, , $repository] = $this->environment();
        $member = User::factory()->create();
        $organization = $owner->currentOrganization;
        $organization->members()->attach($member, ['role' => 'viewer']);
        $member->update(['current_organization_id' => $organization->id]);

        $this->actingAs($owner)->post(route('observability.environments.investigations.store', $environment), [
            'name' => '  Failed production deploys  ',
            'window' => '7d',
            'service' => (string) $repository->id,
            'deployment' => 'unsuccessful',
            'severity' => 'critical',
            'expires_in_days' => 7,
        ])->assertRedirect()->assertSessionHas('success', 'Investigation view saved.');

        $view = ObservabilityInvestigationView::query()->sole();
        $this->assertSame('Failed production deploys', $view->name);
        $this->assertSame([
            'window' => '7d',
            'service' => (string) $repository->id,
            'deployment' => 'unsuccessful',
            'severity' => 'critical',
        ], $view->filters);
        $this->assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/', $view->public_id);
        $this->assertNotSame((string) $view->id, $view->public_id);
        $this->assertSame($organization->id, $view->organization_id);
        $this->assertSame($environment->id, $view->environment_id);
        $this->assertSame($owner->id, $view->created_by);

        $canonical = route('observability.environments.context', [
            'environment' => $environment,
            ...$view->contextFilters()->queryParameters(),
        ]);

        $this->actingAs($member)
            ->get(route('observability.investigations.show', $view))
            ->assertRedirect($canonical);

        $this->actingAs($member)
            ->get($canonical)
            ->assertSuccessful()
            ->assertSee('Failed production deploys')
            ->assertSee('Saved investigations for this environment');

        $this->actingAs($member)
            ->get($canonical.'&dialog=save-investigation')
            ->assertSuccessful()
            ->assertSee('data-modal-trigger="save-investigation-dialog"', false)
            ->assertSee('data-modal-initial-open="true"', false);
    }

    public function test_context_lists_views_for_workspace_members_and_creator_can_remove_one(): void
    {
        [$owner, $environment] = $this->environment();
        $view = $this->createView($owner, $environment, $owner, ['name' => 'Deployment handoff']);

        $this->actingAs($owner)
            ->get(route('observability.environments.context', $environment))
            ->assertSuccessful()
            ->assertSee('Deployment handoff')
            ->assertSee(route('observability.investigations.show', $view), false)
            ->assertSee('Remove investigation Deployment handoff', false);

        $this->actingAs($owner)
            ->delete(route('observability.investigations.destroy', $view))
            ->assertRedirect()
            ->assertSessionHas('success', 'Investigation view removed.');

        $this->assertDatabaseMissing('observability_investigation_views', ['id' => $view->id]);
    }

    public function test_non_member_is_denied_before_malformed_input_and_no_view_is_written(): void
    {
        [$owner, $environment] = $this->environment();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->post(route('observability.environments.investigations.store', $environment), [
                'name' => [],
                'window' => 'unsupported',
                'service' => 'not-a-service',
                'deployment' => 'unsupported',
                'severity' => 'unsupported',
                'expires_in_days' => 999,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('observability_investigation_views', 0);
        $this->assertSame($owner->current_organization_id, $owner->currentOrganization->id);
    }

    public function test_view_policy_rejects_a_view_from_another_current_workspace(): void
    {
        [$foreignOwner, $foreignEnvironment] = $this->environment();
        $foreignView = $this->createView($foreignOwner, $foreignEnvironment, $foreignOwner);
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->get(route('observability.investigations.show', $foreignView))
            ->assertForbidden();
    }

    public function test_expired_view_is_not_openable_but_can_be_removed_by_its_creator(): void
    {
        [$owner, $environment] = $this->environment();
        $view = $this->createView($owner, $environment, $owner, [
            'expires_at' => now()->subMinute(),
        ]);

        $this->actingAs($owner)
            ->get(route('observability.investigations.show', $view))
            ->assertNotFound();

        $this->actingAs($owner)
            ->delete(route('observability.investigations.destroy', $view))
            ->assertRedirect();

        $this->assertDatabaseMissing('observability_investigation_views', ['id' => $view->id]);
    }

    public function test_corrupt_or_stale_service_filters_are_not_redirected(): void
    {
        [$owner, $environment, , $repository] = $this->environment();
        $corrupt = $this->createView($owner, $environment, $owner, [
            'filters' => [
                'window' => '7d',
                'service' => (string) ($repository->id + 100000),
                'deployment' => 'all',
                'severity' => 'all',
            ],
        ]);
        $invalid = $this->createView($owner, $environment, $owner, [
            'name' => 'Invalid persisted filters',
            'filters' => ['window' => 'forever', 'service' => 'all', 'deployment' => 'all', 'severity' => 'all'],
        ]);

        foreach ([$corrupt, $invalid] as $view) {
            $this->actingAs($owner)
                ->get(route('observability.investigations.show', $view))
                ->assertNotFound();
        }
    }

    public function test_duplicate_active_name_is_rejected_without_replacing_the_existing_view(): void
    {
        [$owner, $environment] = $this->environment();
        $existing = $this->createView($owner, $environment, $owner, ['name' => 'Incident review']);

        $this->actingAs($owner)
            ->from(route('observability.environments.context', $environment))
            ->post(route('observability.environments.investigations.store', $environment), [
                'name' => ' incident REVIEW ',
                'window' => '24h',
                'service' => 'all',
                'deployment' => 'all',
                'severity' => 'all',
                'expires_in_days' => 30,
            ])
            ->assertRedirect(route('observability.environments.context', $environment))
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('observability_investigation_views', 1);
        $this->assertSame($existing->id, ObservabilityInvestigationView::query()->sole()->id);
    }

    public function test_active_view_cap_ignores_expired_rows_and_preserves_them_for_pruning(): void
    {
        [$owner, $environment] = $this->environment();
        foreach (range(1, ObservabilityInvestigationView::MAX_ACTIVE_PER_ORGANIZATION - 1) as $number) {
            $this->createView($owner, $environment, $owner, ['name' => "Review {$number}"]);
        }
        $expired = $this->createView($owner, $environment, $owner, [
            'name' => 'Expired slot',
            'expires_at' => now()->subMinute(),
        ]);

        $this->actingAs($owner)
            ->post(route('observability.environments.investigations.store', $environment), [
                'name' => 'Within active limit',
                'window' => '24h',
                'service' => 'all',
                'deployment' => 'all',
                'severity' => 'all',
                'expires_in_days' => 30,
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Investigation view saved.');

        $this->assertDatabaseHas('observability_investigation_views', ['id' => $expired->id]);
        $this->assertSame(ObservabilityInvestigationView::MAX_ACTIVE_PER_ORGANIZATION + 1, ObservabilityInvestigationView::query()->count());
        $this->assertSame(
            ObservabilityInvestigationView::MAX_ACTIVE_PER_ORGANIZATION,
            ObservabilityInvestigationView::query()->where('expires_at', '>', now())->count(),
        );
    }

    public function test_omitted_expiration_uses_the_bounded_default(): void
    {
        [$owner, $environment] = $this->environment();
        $before = now()->addDays(ObservabilityInvestigationView::DEFAULT_EXPIRY_DAYS);

        $this->actingAs($owner)
            ->post(route('observability.environments.investigations.store', $environment), [
                'name' => 'Default retention',
                'window' => '24h',
                'service' => 'all',
                'deployment' => 'all',
                'severity' => 'all',
            ])
            ->assertRedirect();

        $expiresAt = ObservabilityInvestigationView::query()->sole()->expires_at;
        $this->assertTrue($expiresAt->between($before->copy()->subSeconds(2), $before->copy()->addSeconds(2)));
    }

    public function test_prune_command_removes_only_a_bounded_batch_of_expired_views(): void
    {
        $this->travelTo('2026-09-13 12:00:00');
        [$owner, $environment] = $this->environment();
        $expired = collect(range(1, 3))->map(fn (int $number): ObservabilityInvestigationView => $this->createView(
            $owner,
            $environment,
            $owner,
            ['name' => "Expired {$number}", 'expires_at' => now()->subMinutes($number)],
        ));
        $active = $this->createView($owner, $environment, $owner, [
            'name' => 'Still active',
            'expires_at' => now()->addDay(),
        ]);

        $this->artisan('buildpusher:observability:investigations:prune', ['--limit' => 2])
            ->assertSuccessful()
            ->expectsOutput('Pruned 2 expired investigation view(s).');

        $this->assertDatabaseCount('observability_investigation_views', 2);
        $this->assertDatabaseHas('observability_investigation_views', ['id' => $active->id]);
        $this->assertDatabaseHas('observability_investigation_views', ['id' => $expired->last()->id]);

        $this->travelBack();
    }

    /** @return array{User, Environment, Website, Repository} */
    private function environment(): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'Source provider',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'provider-token',
            'description' => 'Source control',
        ]);
        $server = $owner->servers()->create([
            'name' => 'Application server',
            'public_ip' => '203.0.113.10',
            'ssh_private_key' => 'private-key',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $owner->websites()->create([
            'server_id' => $server->id,
            'name' => 'Application website',
            'description' => 'Application',
            'environment' => 'APP_SECRET=private-environment-value',
            'url' => 'app.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $project = $owner->currentOrganization->projects()->create([
            'created_by' => $owner->id,
            'name' => 'Application',
            'slug' => 'application-'.Str::lower(Str::random(6)),
        ]);
        $environment = $project->environments()->create([
            'name' => 'Production',
            'slug' => 'production',
            'type' => 'production',
            'branch' => 'main',
            'server_id' => $server->id,
            'website_id' => $website->id,
        ]);
        $repository = $owner->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => 'Application repository',
            'url' => 'github.com/example/application.git',
            'branch' => 'main',
            'description' => 'Application source',
        ]);

        return [$owner, $environment, $website, $repository];
    }

    /** @param array<string, mixed> $attributes */
    private function createView(User $organizationOwner, Environment $environment, User $creator, array $attributes = []): ObservabilityInvestigationView
    {
        return $organizationOwner->currentOrganization->investigationViews()->create(array_merge([
            'public_id' => (string) Str::uuid(),
            'environment_id' => $environment->id,
            'created_by' => $creator->id,
            'name' => 'Investigation',
            'filters' => [
                'window' => '24h',
                'service' => 'all',
                'deployment' => 'all',
                'severity' => 'all',
            ],
            'expires_at' => now()->addDays(30),
        ], $attributes));
    }
}
