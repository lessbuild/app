<?php

namespace Tests\Feature;

use App\Jobs\Repository\PublishRepositoryJob;
use App\Jobs\Web\AddWebsiteJob;
use App\Jobs\Web\DeleteWebsiteFromCaddyJob;
use App\Models\Build;
use App\Models\EnvironmentResource;
use App\Models\PreviewDeployment;
use App\Models\PreviewSecretApproval;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\RepositoryDeploymentPlan;
use App\Services\WebsiteProvisioningPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PreviewDeploymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.enforce_entitlements' => false]);
    }

    public function test_pull_request_provisions_updates_and_closes_an_isolated_preview(): void
    {
        config(['billing.enforce_limits' => false]);
        Queue::fake();
        [$owner, $source, $project] = $this->application();
        $secret = 'preview-webhook-'.str_repeat('x', 48);
        $source->update(['webhook_enabled' => true, 'webhook_secret' => $secret]);
        $revision = str_repeat('a', 40);
        $payload = $this->payload('opened', $revision);

        $this->send($source, $payload, $secret, 'preview-open')
            ->assertAccepted()
            ->assertJson(['status' => PreviewDeployment::STATUS_PROVISIONING]);

        $preview = PreviewDeployment::query()->sole();
        $this->assertSame('pr-17-storefront.previews.example.com', $preview->url);
        $this->assertSame('preview', $preview->environment->type);
        $this->assertSame('feature/checkout', $preview->repository->branch);
        $this->assertNotSame($source->website_id, $preview->website_id);
        $this->assertEqualsCanonicalizing(['queue', 'scheduler'], $preview->environment->processes()->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['database', 'cache'], $preview->environment->resources()->pluck('name')->all());
        $this->assertSame('php artisan queue:work --sleep=3 --tries=3 --timeout=90', $preview->environment->processes()->where('name', 'queue')->value('command'));
        $this->assertSame(EnvironmentResource::STATUS_PLANNED, $preview->environment->resources()->where('name', 'database')->value('status'));
        $databaseConfiguration = $preview->environment->resources()->where('name', 'database')->firstOrFail()->configuration;
        $this->assertSame($preview->website->database_password, $databaseConfiguration['variables']['DB_PASSWORD']);
        $this->assertSame('pgsql', $databaseConfiguration['variables']['DB_CONNECTION']);
        $cacheConfiguration = $preview->environment->resources()->where('name', 'cache')->firstOrFail()->configuration;
        $this->assertSame('127.0.0.1', $cacheConfiguration['variables']['VALKEY_HOST']);
        $this->assertSame('buildpusher-valkey-'.$preview->environment_id.'-cache', $cacheConfiguration['container_name']);
        $previewWebsite = $preview->website->fresh();
        $previewEnvironment = (string) $previewWebsite->environment;
        $this->assertStringContainsString('APP_ENV="preview"', $previewEnvironment);
        $this->assertStringContainsString('APP_DEBUG="false"', $previewEnvironment);
        $this->assertStringContainsString('APP_URL="https://pr-17-storefront.previews.example.com"', $previewEnvironment);
        $this->assertStringContainsString('BUILDPUSHER_PREVIEW="17"', $previewEnvironment);
        $this->assertStringContainsString('DB_DATABASE="'.$previewWebsite->databaseIdentifier().'"', $previewEnvironment);
        $this->assertStringContainsString('DB_USERNAME="'.$previewWebsite->databaseIdentifier().'"', $previewEnvironment);
        $this->assertStringContainsString('DB_PASSWORD="'.$previewWebsite->database_password.'"', $previewEnvironment);
        $this->assertStringContainsString('APP_KEY="base64:', $previewEnvironment);
        $this->assertStringNotContainsString('base64:source-production-key', $previewEnvironment);
        $this->assertStringNotContainsString('production-api-secret', $previewEnvironment);
        $this->assertStringNotContainsString('production-mail-secret', $previewEnvironment);
        $this->assertNotSame('source-database-secret', $previewWebsite->database_password);
        $this->assertStringNotContainsString('production-api-secret', (string) DB::table('websites')->whereKey($previewWebsite->id)->value('environment'));
        $this->assertStringNotContainsString('production-api-secret', $previewWebsite->toJson());
        Queue::assertPushed(AddWebsiteJob::class, fn (AddWebsiteJob $job): bool => $job->website->is($preview->website));

        $preview->website->update(['provisioning_status' => Website::STATUS_PROVISIONING]);
        $this->post(URL::signedRoute('callbacks.website', [
            'website' => $preview->website,
            'attempt' => $preview->website->provisioning_token,
        ]), ['status' => app(WebsiteProvisioningPlan::class)->finalStage()])->assertOk();

        $build = $preview->repository->builds()->sole();
        $this->assertSame($revision, $build->revision);
        $this->assertEqualsCanonicalizing(['queue', 'scheduler'], array_column($build->environment_payload['processes'], 'name'));
        $this->assertEqualsCanonicalizing(['postgresql', 'valkey'], array_column($build->environment_payload['resources'], 'type'));
        $databasePayload = collect($build->environment_payload['resources'])->first(fn (array $resource): bool => $resource['type'] === 'postgresql');
        $this->assertIsArray($databasePayload);
        $this->assertSame($preview->website->database_password, $databasePayload['configuration']['variables']['DB_PASSWORD']);
        $this->assertStringNotContainsString('source-production-key', (string) $build->environment_payload['base_environment']);
        Queue::assertPushed(PublishRepositoryJob::class, fn (PublishRepositoryJob $job): bool => $job->build->is($build));

        $build->update(['status' => Build::STATUS_RUNNING]);
        $this->post(URL::signedRoute('callbacks.build.status', $build), [
            'status' => app(RepositoryDeploymentPlan::class)->finalStage(),
        ])->assertNoContent();
        $this->assertSame(PreviewDeployment::STATUS_READY, $preview->fresh()->status);

        $closed = $this->payload('closed', $revision);
        $this->send($source, $closed, $secret, 'preview-close')
            ->assertOk()
            ->assertJson(['status' => PreviewDeployment::STATUS_CLOSED]);
        $this->assertNotNull($preview->fresh()->closed_at);
        $this->assertTrue($preview->website->fresh()->trashed());
        Queue::assertPushed(DeleteWebsiteFromCaddyJob::class);
    }

    public function test_preview_settings_are_workspace_scoped_and_validated(): void
    {
        [$owner, , $project] = $this->application(previews: false);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->patch(route('projects.previews.update', $project), [
            'preview_enabled' => '1', 'preview_domain' => 'outside.example.com', 'preview_ttl_hours' => 24,
        ])->assertForbidden();
        $this->actingAs($owner)->patch(route('projects.previews.update', $project), [
            'preview_enabled' => '1', 'preview_domain' => 'https://previews.example.com/', 'preview_ttl_hours' => 48,
        ])->assertRedirect();

        $project->refresh();
        $this->assertTrue($project->preview_enabled);
        $this->assertSame('previews.example.com', $project->preview_domain);
        $this->assertSame(48, $project->preview_ttl_hours);
    }

    public function test_existing_preview_is_sanitized_before_a_revised_revision_is_queued(): void
    {
        config(['billing.enforce_limits' => false]);
        Queue::fake();
        [, $source] = $this->application();
        $secret = 'preview-webhook-'.str_repeat('x', 48);
        $source->update(['webhook_enabled' => true, 'webhook_secret' => $secret]);

        $this->send($source, $this->payload('opened', str_repeat('a', 40)), $secret, 'preview-open')
            ->assertAccepted();
        $preview = PreviewDeployment::query()->sole();
        $preview->website->update([
            'environment' => "APP_ENV=production\nAPP_KEY=base64:legacy-preview-key\nAPI_TOKEN=legacy-preview-secret",
        ]);

        $this->send($source, $this->payload('synchronize', str_repeat('b', 40)), $secret, 'preview-update')
            ->assertAccepted();

        $this->assertSame(2, $preview->environment()->firstOrFail()->processes()->count());
        $this->assertSame(2, $preview->environment()->firstOrFail()->resources()->count());

        $environment = (string) $preview->website->fresh()->environment;
        $this->assertStringContainsString('APP_ENV="preview"', $environment);
        $this->assertStringNotContainsString('base64:legacy-preview-key', $environment);
        $this->assertStringNotContainsString('legacy-preview-secret', $environment);
    }

    public function test_preview_secrets_require_manager_approval_for_the_exact_revision(): void
    {
        config(['billing.enforce_limits' => false]);
        Queue::fake();
        [$owner, $source, $project] = $this->application();
        $environment = $project->environments()->where('type', 'production')->sole();
        $variable = $environment->variables()->create([
            'key' => 'PREVIEW_API_TOKEN', 'value' => 'preview-approved-secret', 'is_secret' => true,
            'scope' => 'runtime', 'current_version' => 4, 'updated_by' => $owner->id,
        ]);
        $environment->resources()->create([
            'name' => 'external-cache', 'type' => 'object_storage', 'is_managed' => false,
            'configuration' => ['variables' => ['OBJECT_STORAGE_SECRET' => 'resource-private-secret']],
        ]);
        $secret = 'preview-webhook-'.str_repeat('x', 48);
        $source->update(['webhook_enabled' => true, 'webhook_secret' => $secret]);
        $revision = str_repeat('2', 40);

        $this->send($source, $this->payload('opened', $revision), $secret, 'preview-secret-open')
            ->assertAccepted();
        $preview = PreviewDeployment::query()->sole();
        $safeEnvironment = (string) $preview->website->fresh()->environment;
        $this->assertStringNotContainsString('preview-approved-secret', $safeEnvironment);
        $this->assertStringNotContainsString('resource-private-secret', $safeEnvironment);

        $this->actingAs($owner)->post(route('projects.previews.secrets.approve', [$project, $preview]), [
            'revision' => $revision,
            'secret_keys' => [$variable->key],
        ])->assertRedirect()->assertSessionHas('success', 'Selected preview secrets were approved for this revision. The next verified update will apply them.');

        $approval = PreviewSecretApproval::query()->sole();
        $this->assertSame($revision, $approval->revision);
        $this->assertSame($environment->id, $approval->source_environment_id);
        $this->assertSame([(string) $variable->id => 4], $approval->variable_versions);
        $this->assertStringNotContainsString('preview-approved-secret', $approval->toJson());

        $this->send($source, $this->payload('synchronize', $revision), $secret, 'preview-secret-sync')
            ->assertAccepted();
        $configured = (string) $preview->website->fresh()->environment;
        $this->assertStringContainsString('PREVIEW_API_TOKEN="preview-approved-secret"', $configured);
        $this->assertStringNotContainsString('production-api-secret', $configured);
        $this->assertStringNotContainsString('resource-private-secret', $configured);
    }

    public function test_preview_secret_scope_requires_management_and_rejects_preview_owned_credentials(): void
    {
        config(['billing.enforce_limits' => false]);
        Queue::fake();
        [$owner, $source, $project] = $this->application();
        $environment = $project->environments()->where('type', 'production')->sole();
        $protected = $environment->variables()->create([
            'key' => 'DB_PASSWORD', 'value' => 'attempted-override', 'is_secret' => true,
            'scope' => 'runtime', 'current_version' => 1, 'updated_by' => $owner->id,
        ]);
        $secret = 'preview-webhook-'.str_repeat('x', 48);
        $source->update(['webhook_enabled' => true, 'webhook_secret' => $secret]);
        $this->send($source, $this->payload('opened', str_repeat('3', 40)), $secret, 'preview-protected-open')
            ->assertAccepted();
        $preview = PreviewDeployment::query()->sole();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->post(route('projects.previews.secrets.approve', [$project, $preview]), [
            'revision' => $preview->revision,
            'secret_keys' => [$protected->key],
        ])->assertForbidden();
        $this->assertDatabaseCount('preview_secret_approvals', 0);

        $this->actingAs($owner)->post(route('projects.previews.secrets.approve', [$project, $preview]), [
            'revision' => $preview->revision,
            'secret_keys' => [$protected->key],
        ])->assertSessionHasErrors(['secret_keys'], errorBag: 'preview_secrets')
            ->assertSessionMissing('_old_input.secret_keys');
        $this->assertDatabaseCount('preview_secret_approvals', 0);
    }

    public function test_secret_approval_rejects_a_stale_revision_from_the_preview_page(): void
    {
        config(['billing.enforce_limits' => false]);
        Queue::fake();
        [$owner, $source, $project] = $this->application();
        $environment = $project->environments()->where('type', 'production')->sole();
        $variable = $environment->variables()->create([
            'key' => 'PREVIEW_STALE_TOKEN', 'value' => 'stale-secret', 'is_secret' => true,
            'scope' => 'runtime', 'current_version' => 1, 'updated_by' => $owner->id,
        ]);
        $secret = 'preview-webhook-'.str_repeat('x', 48);
        $source->update(['webhook_enabled' => true, 'webhook_secret' => $secret]);
        $oldRevision = str_repeat('7', 40);
        $currentRevision = str_repeat('8', 40);
        $this->send($source, $this->payload('opened', $oldRevision), $secret, 'preview-stale-open')->assertAccepted();
        $preview = PreviewDeployment::query()->sole();
        $this->send($source, $this->payload('synchronize', $currentRevision), $secret, 'preview-stale-update')->assertAccepted();

        $this->actingAs($owner)->post(route('projects.previews.secrets.approve', [$project, $preview]), [
            'revision' => $oldRevision,
            'secret_keys' => [$variable->key],
        ])->assertRedirect()->assertSessionHas('info', 'This preview is closed, unavailable, or no longer belongs to your managed workspace.');

        $this->assertDatabaseCount('preview_secret_approvals', 0);
    }

    public function test_secret_rotation_and_revision_change_invalidate_preview_approval(): void
    {
        config(['billing.enforce_limits' => false]);
        Queue::fake();
        [$owner, $source, $project] = $this->application();
        $environment = $project->environments()->where('type', 'production')->sole();
        $variable = $environment->variables()->create([
            'key' => 'PREVIEW_ROTATING_TOKEN', 'value' => 'version-one', 'is_secret' => true,
            'scope' => 'all', 'current_version' => 1, 'updated_by' => $owner->id,
        ]);
        $secret = 'preview-webhook-'.str_repeat('x', 48);
        $source->update(['webhook_enabled' => true, 'webhook_secret' => $secret]);
        $firstRevision = str_repeat('4', 40);
        $secondRevision = str_repeat('5', 40);
        $this->send($source, $this->payload('opened', $firstRevision), $secret, 'preview-rotation-open')->assertAccepted();
        $preview = PreviewDeployment::query()->sole();

        $this->actingAs($owner)->post(route('projects.previews.secrets.approve', [$project, $preview]), [
            'revision' => $firstRevision,
            'secret_keys' => [$variable->key],
        ])->assertRedirect();
        $variable->update(['value' => 'version-two', 'current_version' => 2]);

        $this->send($source, $this->payload('synchronize', $firstRevision), $secret, 'preview-rotation-stale')
            ->assertAccepted();
        $staleEnvironment = (string) $preview->website->fresh()->environment;
        $this->assertStringNotContainsString('version-one', $staleEnvironment);
        $this->assertStringNotContainsString('version-two', $staleEnvironment);

        $this->send($source, $this->payload('synchronize', $secondRevision), $secret, 'preview-rotation-new-revision')
            ->assertAccepted();
        $this->assertNotNull(PreviewSecretApproval::query()->sole()->fresh()->revoked_at);
        $newEnvironment = (string) $preview->website->fresh()->environment;
        $this->assertStringNotContainsString('version-one', $newEnvironment);
        $this->assertStringNotContainsString('version-two', $newEnvironment);
    }

    public function test_closing_a_preview_revokes_its_secret_approval(): void
    {
        config(['billing.enforce_limits' => false]);
        Queue::fake();
        [$owner, $source, $project] = $this->application();
        $environment = $project->environments()->where('type', 'production')->sole();
        $variable = $environment->variables()->create([
            'key' => 'PREVIEW_CLOSE_TOKEN', 'value' => 'close-secret', 'is_secret' => true,
            'scope' => 'runtime', 'current_version' => 1, 'updated_by' => $owner->id,
        ]);
        $secret = 'preview-webhook-'.str_repeat('x', 48);
        $source->update(['webhook_enabled' => true, 'webhook_secret' => $secret]);
        $revision = str_repeat('6', 40);
        $this->send($source, $this->payload('opened', $revision), $secret, 'preview-close-open')->assertAccepted();
        $preview = PreviewDeployment::query()->sole();
        $this->actingAs($owner)->post(route('projects.previews.secrets.approve', [$project, $preview]), [
            'revision' => $revision,
            'secret_keys' => [$variable->key],
        ])->assertRedirect();

        $this->send($source, $this->payload('closed', $revision), $secret, 'preview-close-secret')
            ->assertOk()->assertJson(['status' => PreviewDeployment::STATUS_CLOSED]);
        $this->assertNotNull(PreviewSecretApproval::query()->sole()->fresh()->revoked_at);
    }

    public function test_pull_request_targeting_a_different_branch_is_ignored_without_side_effects(): void
    {
        config(['billing.enforce_limits' => false]);
        Queue::fake();
        [, $source] = $this->application();
        $secret = 'preview-webhook-'.str_repeat('x', 48);
        $source->update(['webhook_enabled' => true, 'webhook_secret' => $secret]);

        $this->send($source, $this->payload('opened', str_repeat('c', 40), 'release'), $secret, 'preview-wrong-target')
            ->assertOk()
            ->assertJson(['status' => 'preview_target_ignored']);

        $this->assertDatabaseCount('preview_deployments', 0);
        $this->assertDatabaseCount('websites', 1);
        $this->assertDatabaseCount('repositories', 1);
        $this->assertDatabaseCount('environments', 1);
        Queue::assertNothingPushed();
    }

    public function test_forked_pull_request_is_blocked_without_side_effects(): void
    {
        config(['billing.enforce_limits' => false]);
        Queue::fake();
        [, $source] = $this->application();
        $secret = 'preview-webhook-'.str_repeat('x', 48);
        $source->update(['webhook_enabled' => true, 'webhook_secret' => $secret]);

        $this->send($source, $this->payload('opened', str_repeat('d', 40), 'main', 'contributor/storefront', 'example/storefront'), $secret, 'preview-fork')
            ->assertOk()
            ->assertJson(['status' => 'preview_fork_blocked']);

        $this->assertDatabaseCount('preview_deployments', 0);
        $this->assertDatabaseCount('websites', 1);
        $this->assertDatabaseCount('repositories', 1);
        $this->assertDatabaseCount('environments', 1);
        Queue::assertNothingPushed();
    }

    public function test_preview_without_provider_trust_metadata_is_blocked_without_side_effects(): void
    {
        config(['billing.enforce_limits' => false]);
        Queue::fake();
        [, $source] = $this->application();
        $secret = 'preview-webhook-'.str_repeat('x', 48);
        $source->update(['webhook_enabled' => true, 'webhook_secret' => $secret]);

        $this->send($source, $this->payload('opened', str_repeat('e', 40), null, null, null), $secret, 'preview-unverified')
            ->assertOk()
            ->assertJson(['status' => 'preview_target_unverified']);

        $this->assertDatabaseCount('preview_deployments', 0);
        $this->assertDatabaseCount('websites', 1);
        $this->assertDatabaseCount('repositories', 1);
        $this->assertDatabaseCount('environments', 1);
        Queue::assertNothingPushed();
    }

    public function test_preview_without_source_repository_metadata_is_blocked_without_side_effects(): void
    {
        config(['billing.enforce_limits' => false]);
        Queue::fake();
        [, $source] = $this->application();
        $secret = 'preview-webhook-'.str_repeat('x', 48);
        $source->update(['webhook_enabled' => true, 'webhook_secret' => $secret]);

        $this->send($source, $this->payload('opened', str_repeat('f', 40), 'main', null), $secret, 'preview-source-unverified')
            ->assertOk()
            ->assertJson(['status' => 'preview_source_unverified']);

        $this->assertDatabaseCount('preview_deployments', 0);
        $this->assertDatabaseCount('websites', 1);
        $this->assertDatabaseCount('repositories', 1);
        $this->assertDatabaseCount('environments', 1);
        Queue::assertNothingPushed();
    }

    public function test_preview_target_repository_must_match_the_configured_source(): void
    {
        config(['billing.enforce_limits' => false]);
        Queue::fake();
        [, $source] = $this->application();
        $secret = 'preview-webhook-'.str_repeat('x', 48);
        $source->update(['webhook_enabled' => true, 'webhook_secret' => $secret]);

        $this->send($source, $this->payload('opened', str_repeat('1', 40), 'main', 'other/storefront', 'other/storefront'), $secret, 'preview-source-mismatch')
            ->assertOk()
            ->assertJson(['status' => 'preview_source_ignored']);

        $this->assertDatabaseCount('preview_deployments', 0);
        $this->assertDatabaseCount('websites', 1);
        $this->assertDatabaseCount('repositories', 1);
        $this->assertDatabaseCount('environments', 1);
        Queue::assertNothingPushed();
    }

    public function test_gitlab_merge_request_preview_uses_target_project_and_branch_metadata(): void
    {
        config(['billing.enforce_limits' => false]);
        Queue::fake();
        [, $source] = $this->application(providerType: Provider::TYPE_GITLAB);
        $secret = 'whsec_'.base64_encode(random_bytes(32));
        $source->update(['webhook_enabled' => true, 'webhook_secret' => $secret]);
        $revision = str_repeat('f', 40);
        $payload = [
            'object_attributes' => [
                'action' => 'open', 'iid' => 17, 'title' => 'Preview checkout',
                'source_branch' => 'feature/checkout', 'target_branch' => 'main',
                'source_project_id' => 42, 'target_project_id' => 42,
                'last_commit' => ['id' => $revision],
            ],
            'project' => ['path_with_namespace' => 'example/storefront'],
        ];
        $delivery = 'gitlab-preview';
        $timestamp = now()->getTimestamp();

        $this->sendWithHeaders($source, $payload, [
            'webhook-id' => $delivery,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => 'v1,'.base64_encode(hash_hmac(
                'sha256', $delivery.'.'.$timestamp.'.'.$this->raw($payload), base64_decode(substr($secret, 6), true), true,
            )),
            'X-Gitlab-Event' => 'Merge Request Hook',
        ])->assertStatus(202)->assertJson(['status' => PreviewDeployment::STATUS_PROVISIONING]);

        $this->assertDatabaseHas('preview_deployments', ['pull_request_number' => 17, 'revision' => $revision]);
    }

    public function test_bitbucket_pull_request_preview_uses_destination_and_source_repository_metadata(): void
    {
        config(['billing.enforce_limits' => false]);
        Queue::fake();
        [, $source] = $this->application(providerType: Provider::TYPE_BITBUCKET);
        $secret = 'preview-webhook-'.str_repeat('x', 48);
        $source->update(['webhook_enabled' => true, 'webhook_secret' => $secret]);
        $revision = str_repeat('7', 40);
        $payload = [
            'pullrequest' => [
                'id' => 17, 'title' => 'Preview checkout',
                'source' => [
                    'branch' => ['name' => 'feature/checkout'], 'commit' => ['hash' => $revision],
                    'repository' => ['full_name' => 'example/storefront'],
                ],
                'destination' => [
                    'branch' => ['name' => 'main'], 'repository' => ['full_name' => 'example/storefront'],
                ],
            ],
        ];
        $delivery = 'bitbucket-preview';

        $this->sendWithHeaders($source, $payload, [
            'X-Hub-Signature' => 'sha256='.hash_hmac('sha256', $this->raw($payload), $secret),
            'X-Request-UUID' => $delivery,
            'X-Event-Key' => 'pullrequest:created',
        ])->assertStatus(202)->assertJson(['status' => PreviewDeployment::STATUS_PROVISIONING]);

        $this->assertDatabaseHas('preview_deployments', ['pull_request_number' => 17, 'revision' => $revision]);
    }

    public function test_preview_settings_entitlement_is_checked_before_validation_and_writes(): void
    {
        config(['billing.enforce_entitlements' => true]);
        [$owner, , $project] = $this->application(previews: false);

        $this->actingAs($owner)->from(route('projects.show', $project))
            ->patch(route('projects.previews.update', $project), [
                'preview_enabled' => '1',
                'preview_domain' => '',
                'preview_ttl_hours' => 'not-an-integer',
            ])
            ->assertRedirect(route('projects.show', $project))
            ->assertSessionHasErrors('plan')
            ->assertSessionMissing('_old_input.preview_domain');

        $this->assertFalse($project->fresh()->preview_enabled);
        $this->assertNull($project->fresh()->preview_domain);
    }

    public function test_project_deletion_uses_the_existing_authorization_and_cascade_behavior(): void
    {
        [$owner, , $project] = $this->application();
        $project->environments()->create([
            'name' => 'Staging', 'slug' => 'staging', 'type' => 'staging', 'branch' => 'develop',
        ]);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->delete(route('projects.destroy', $project))->assertForbidden();
        $this->assertDatabaseHas('projects', ['id' => $project->id]);

        $this->actingAs($owner)->delete(route('projects.destroy', $project))
            ->assertRedirect(route('projects.index'))
            ->assertSessionHas('success', 'Application deleted.');

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseMissing('environments', ['project_id' => $project->id]);
    }

    /** @return array{User, Repository, Project} */
    private function application(bool $previews = true, string $providerType = Provider::TYPE_GITHUB): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => ucfirst($providerType), 'provider' => $providerType, 'token' => 'token', 'description' => 'Source',
        ]);
        $server = $owner->servers()->create(['name' => 'Production', 'provisioning_status' => Server::STATUS_ACTIVE]);
        $website = $owner->websites()->create([
            'server_id' => $server->id, 'name' => 'Storefront', 'description' => 'Website',
            'environment' => "APP_ENV=production\nAPP_KEY=base64:source-production-key\nAPI_TOKEN=production-api-secret\nMAIL_PASSWORD=production-mail-secret",
            'database_password' => 'source-database-secret', 'url' => 'store.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $repository = $owner->repositories()->create([
            'provider_id' => $provider->id, 'website_id' => $website->id, 'name' => 'Storefront source',
            'url' => $provider->repositoryHost().'/example/storefront.git', 'branch' => 'main', 'description' => 'Source',
        ]);
        $project = $owner->currentOrganization->projects()->create([
            'created_by' => $owner->id, 'name' => 'Storefront', 'slug' => 'storefront',
            'preview_enabled' => $previews, 'preview_domain' => $previews ? 'previews.example.com' : null,
            'preview_ttl_hours' => 72,
        ]);
        $project->environments()->create([
            'name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main',
            'server_id' => $server->id, 'website_id' => $website->id, 'is_protected' => true,
        ]);

        return [$owner, $repository, $project];
    }

    private function payload(
        string $action,
        string $revision,
        ?string $targetBranch = 'main',
        ?string $headRepository = 'example/storefront',
        ?string $baseRepository = 'example/storefront',
    ): array {
        $pullRequest = [
            'action' => $action,
            'number' => 17,
            'pull_request' => [
                'title' => 'Preview checkout',
                'head' => ['ref' => 'feature/checkout', 'sha' => $revision],
            ],
        ];

        if ($headRepository !== null) {
            $pullRequest['pull_request']['head']['repo'] = ['full_name' => $headRepository];
        }
        if ($targetBranch !== null) {
            $pullRequest['pull_request']['base'] = ['ref' => $targetBranch];
            if ($baseRepository !== null) {
                $pullRequest['pull_request']['base']['repo'] = ['full_name' => $baseRepository];
            }
        }

        return $pullRequest;
    }

    private function send(Repository $repository, array $payload, string $secret, string $delivery): TestResponse
    {
        return $this->sendWithHeaders($repository, $payload, [
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $this->raw($payload), $secret),
            'X-GitHub-Delivery' => $delivery,
            'X-GitHub-Event' => 'pull_request',
        ]);
    }

    /** @param array<string, string> $headers */
    private function sendWithHeaders(Repository $repository, array $payload, array $headers): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call(
            'POST',
            route('webhooks.repositories.receive', $repository),
            server: $server,
            content: $this->raw($payload),
        );
    }

    private function raw(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
