<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\ConfigurationOwnership;
use App\Models\ConfigurationReview;
use App\Models\Environment;
use App\Models\PreviewDeployment;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\ApplicationConfigurationDocument;
use App\Services\ApplicationConfigurationPlanner;
use App\Services\ApplicationConfigurationReconciler;
use App\Services\ApplicationConfigurationReviews;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class ApplicationConfigurationEnvironmentRemovalTest extends TestCase
{
    use RefreshDatabase;

    private const REMOVE = "version: 2\nremove:\n  environments: [staging]\n";

    public function test_schema_accepts_removal_only_and_mixed_documents(): void
    {
        $documents = app(ApplicationConfigurationDocument::class);
        foreach ([self::REMOVE, self::REMOVE."environments: {}\n"] as $yaml) {
            $parsed = $documents->parse($yaml);
            $this->assertSame([], $parsed['environments']);
            $this->assertSame(['staging'], $parsed['remove']['environments']);
        }
        $parsed = $documents->parse(self::REMOVE."environments:\n  development:\n    type: development\n    placement: site\n    runtime: {type: php}\n");
        $this->assertSame(['development'], array_keys($parsed['environments']));
        $this->assertSame(['staging'], $parsed['remove']['environments']);
        $maximum = ['version' => 2, 'remove' => ['environments' => array_map(fn ($i) => 'stage-'.$i, range(1, 20))]];
        $this->assertCount(20, $documents->parse(Yaml::dump($maximum))['remove']['environments']);
    }

    #[DataProvider('invalidDocuments')]
    public function test_schema_rejects_invalid_removals_without_disclosing_input(string $yaml): void
    {
        $this->assertInvalid(fn () => app(ApplicationConfigurationDocument::class)->parse($yaml), 'document');
    }

    public static function invalidDocuments(): array
    {
        return [
            'missing changes' => ["version: 2\n"],
            'empty declarations' => ["version: 2\nenvironments: {}\n"],
            'empty removal object' => ["version: 2\nremove: {}\n"],
            'empty removal list' => ["version: 2\nremove: {environments: []}\n"],
            'duplicate names' => ["version: 2\nremove: {environments: [staging, staging]}\n"],
            'mapping instead of list' => ["version: 2\nremove: {environments: {name: staging}}\n"],
            'scalar instead of list' => ["version: 2\nremove: {environments: staging}\n"],
            'null list' => ["version: 2\nremove: {environments: null}\n"],
            'numeric name' => ["version: 2\nremove: {environments: [123]}\n"],
            'boolean name' => ["version: 2\nremove: {environments: [true]}\n"],
            'invalid name' => ["version: 2\nremove: {environments: ['private-input/secret']}\n"],
            'overlong name' => ["version: 2\nremove: {environments: ['".str_repeat('a', 101)."']}\n"],
            'too many names' => ["version: 2\nremove: {environments: [".implode(', ', array_map(fn ($i) => 'stage-'.$i, range(1, 21)))."]}\n"],
            'unknown removal kind' => ["version: 2\nremove: {environments: [staging], websites: [site]}\n"],
            'unknown root field' => [self::REMOVE."private-input: secret\n"],
            'duplicate yaml key' => [self::REMOVE."remove: {environments: [development]}\n"],
            'declaration conflict' => [self::REMOVE."environments:\n  staging:\n    adopt: true\n    type: staging\n    placement: site\n    runtime: {type: php}\n"],
        ];
    }

    public function test_plan_lists_every_child_and_is_read_only_without_secret_or_command_disclosure(): void
    {
        $fixture = $this->fixture();
        $before = $this->snapshot();
        $plan = $this->plan($fixture);
        $this->assertSame([
            ['processes', 'worker', 'remove'],
            ['resources', 'cache', 'detach'],
            ['variables', 'API_TOKEN', 'remove'],
            ['environment', 'staging', 'remove'],
        ], array_map(fn ($change) => [$change['kind'], $change['name'], $change['action']], $plan['changes']));
        foreach ($plan['changes'] as $change) {
            $this->assertSame('staging', $change['environment']);
            $this->assertFalse($change['remote_data_deleted']);
            $this->assertFalse($change['remote_services_changed']);
            $this->assertSame([], $change['fields']);
        }
        $this->assertTrue($plan['apply_available']);
        foreach (['private-secret-value', 'private-worker-command', 'private-build-command'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, json_encode($plan));
        }
        $this->assertSame($before, $this->snapshot());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_removal_deletes_reviewed_local_records_and_preserves_remote_targets_and_history(): void
    {
        $fixture = $this->fixture();
        $environment = $fixture['environment'];
        $variable = $environment->variables()->firstOrFail();
        $variable->versions()->create(['version' => 2, 'value' => 'older-secret', 'created_by' => $fixture['user']->id]);
        $build = $fixture['repository']->builds()->create([
            'environment_id' => $environment->id, 'status' => Build::STATUS_SUCCEEDED,
            'environment_payload' => ['variables' => ['API_TOKEN' => 'historical-secret']], 'finished_at' => now(),
        ]);
        $operation = $fixture['application']->operations()->create([
            'environment_slug' => 'staging', 'environment_id' => $environment->id, 'build_id' => $build->id,
            'kind' => 'deploy', 'status' => 'succeeded', 'payload' => ['historical' => 'private-payload'],
        ]);
        $preview = $this->preview($fixture, PreviewDeployment::STATUS_CLOSED, true);
        $remoteBefore = $this->snapshot(['servers', 'websites', 'repositories']);
        $buildBefore = $build->fresh()->getRawOriginal();
        $operationBefore = $operation->fresh()->getRawOriginal();
        $review = $this->review($fixture);
        $receipt = app(ApplicationConfigurationReconciler::class)->apply($review, $fixture['user']);
        $this->assertSame('locally_applied', $receipt->status);
        $this->assertSame($review->summary, $review->fresh()->summary);
        $this->assertDatabaseMissing('environments', ['id' => $environment->id]);
        foreach (['environment_processes', 'environment_resources', 'environment_variables'] as $table) {
            $this->assertDatabaseMissing($table, ['environment_id' => $environment->id]);
        }
        $this->assertDatabaseMissing('environment_variable_versions', ['environment_variable_id' => $variable->id]);
        $this->assertDatabaseMissing('configuration_ownerships', ['project_id' => $fixture['project']->id, 'environment_slug' => 'staging']);
        $this->assertSame($remoteBefore, $this->snapshot(['servers', 'websites', 'repositories']));
        $this->assertSame(array_replace($buildBefore, ['environment_id' => null]), $build->fresh()->getRawOriginal());
        $this->assertSame(array_replace($operationBefore, ['environment_id' => null]), $operation->fresh()->getRawOriginal());
        $this->assertSame('historical-secret', $build->fresh()->environment_payload['variables']['API_TOKEN']);
        $this->assertNull($preview->fresh()->environment_id);
        $this->assertSame(PreviewDeployment::STATUS_CLOSED, $preview->fresh()->status);
        $this->assertSame('private-secret-value', $fixture['source']->fresh()->value);
        $this->assertDatabaseCount('configuration_operations', 1);
        $this->assertDatabaseCount('builds', 1);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    #[DataProvider('ownershipConflicts')]
    public function test_removal_rejects_manual_foreign_and_stale_ownership(string $kind, string $conflict): void
    {
        $fixture = $this->fixture();
        $ownership = ConfigurationOwnership::query()->where('project_id', $fixture['project']->id)->where('kind', $kind)->firstOrFail();
        if ($conflict === 'manual') {
            $ownership->delete();
        } elseif ($conflict === 'foreign') {
            $foreign = User::factory()->create();
            $otherProject = $foreign->currentOrganization->projects()->create(['name' => 'Foreign', 'slug' => 'foreign', 'created_by' => $foreign->id]);
            $ownership->update(['project_id' => $otherProject->id]);
        } elseif ($conflict === 'identity') {
            $ownership->update(['environment_slug' => 'different']);
        } else {
            $ownership->update(['resource_id' => 999999]);
        }
        $before = $this->snapshot();
        $this->assertInvalid(fn () => $this->plan($fixture), 'plan');
        $this->assertSame($before, $this->snapshot());
    }

    public static function ownershipConflicts(): array
    {
        $cases = [];
        foreach (['environment', 'processes', 'resources', 'variables'] as $kind) {
            foreach (['manual', 'foreign', 'identity', 'stale'] as $conflict) {
                $cases[$kind.' '.$conflict] = [$kind, $conflict];
            }
        }

        return $cases;
    }

    public function test_removal_rejects_stale_child_ownership_even_when_the_child_or_environment_is_absent(): void
    {
        $fixture = $this->fixture();
        $fixture['environment']->processes()->delete();
        $this->assertInvalid(fn () => $this->plan($fixture), 'plan');
        $fixture['environment']->delete();
        ConfigurationOwnership::query()->where('kind', 'environment')->delete();
        $this->assertInvalid(fn () => $this->plan($fixture), 'plan');
        $this->assertDatabaseCount('configuration_applications', 1);
    }

    #[DataProvider('protectedEnvironments')]
    public function test_removal_rejects_production_and_protected_environments(array $attributes): void
    {
        $fixture = $this->fixture();
        $fixture['environment']->update($attributes);
        $this->assertInvalid(fn () => $this->plan($fixture), 'plan');
        $this->assertNotNull($fixture['environment']->fresh());
    }

    public static function protectedEnvironments(): array
    {
        return ['production' => [['type' => 'production']], 'protected staging' => [['is_protected' => true]]];
    }

    #[DataProvider('activeBuilds')]
    public function test_active_environment_or_website_build_blocks_removal_before_and_after_review(string $status, bool $attached): void
    {
        $fixture = $this->fixture();
        $review = $this->review($fixture);
        $fixture['repository']->builds()->create(['environment_id' => $attached ? $fixture['environment']->id : null, 'status' => $status]);
        $before = $this->snapshot();
        $this->assertInvalid(fn () => $this->plan($fixture), 'plan');
        $this->assertInvalid(fn () => app(ApplicationConfigurationReconciler::class)->apply($review, $fixture['user']));
        $this->assertSame($before, $this->snapshot());
        $this->assertNull($review->fresh()->applied_at);
    }

    public static function activeBuilds(): array
    {
        $cases = [];
        foreach (Build::ACTIVE_STATUSES as $status) {
            $cases[$status.' environment build'] = [$status, true];
            $cases[$status.' website build'] = [$status, false];
        }

        return $cases;
    }

    #[DataProvider('outstandingOperations')]
    public function test_outstanding_configuration_operation_blocks_removal_before_and_after_review(string $status): void
    {
        $fixture = $this->fixture();
        $review = $this->review($fixture);
        $fixture['application']->operations()->create([
            'environment_slug' => 'staging', 'environment_id' => $fixture['environment']->id,
            'kind' => 'deploy', 'status' => $status, 'payload' => [],
        ]);
        $before = $this->snapshot();
        $this->assertInvalid(fn () => $this->plan($fixture), 'plan');
        $this->assertInvalid(fn () => app(ApplicationConfigurationReconciler::class)->apply($review, $fixture['user']));
        $this->assertSame($before, $this->snapshot());
        $this->assertNull($review->fresh()->applied_at);
    }

    public static function outstandingOperations(): array
    {
        return array_map(fn ($status) => [$status], [
            'pending', 'build_created', 'awaiting_approval', 'blocked', 'delivering', 'delivery_failed', 'dispatched', 'unknown_future_state',
        ]);
    }

    #[DataProvider('dependencies')]
    public function test_attached_automation_load_balancers_and_open_previews_block_removal_before_and_after_review(string $dependency): void
    {
        $fixture = $this->fixture();
        $review = $this->review($fixture);
        $environment = $fixture['environment'];
        $schedule = ['created_by' => $fixture['user']->id, 'name' => 'disabled-but-attached', 'cron_expression' => '* * * * *', 'is_enabled' => false];
        $record = match ($dependency) {
            'deployment schedule' => $environment->deploymentSchedules()->create($schedule),
            'scaling schedule' => $environment->scalingSchedules()->create($schedule + ['replicas' => 2]),
            'scheduled task' => $environment->scheduledTasks()->create($schedule + ['command' => 'private-task-command']),
            'load balancer' => $environment->loadBalancers()->create([
                'organization_id' => $fixture['project']->organization_id, 'server_id' => $fixture['server']->id,
                'created_by' => $fixture['user']->id, 'hostname' => 'balancer.example',
            ]),
            'closed without timestamp' => $this->preview($fixture, PreviewDeployment::STATUS_CLOSED),
            default => $this->preview($fixture, $dependency),
        };
        $before = $this->snapshot();
        $this->assertInvalid(fn () => $this->plan($fixture), 'plan');
        $this->assertInvalid(fn () => app(ApplicationConfigurationReconciler::class)->apply($review, $fixture['user']));
        $this->assertSame($before, $this->snapshot());
        $this->assertNotNull($record->fresh());
        $this->assertNull($review->fresh()->applied_at);
        Queue::assertNothingPushed();
    }

    public static function dependencies(): array
    {
        return array_map(fn ($dependency) => [$dependency], [
            'deployment schedule', 'scaling schedule', 'scheduled task', 'load balancer',
            PreviewDeployment::STATUS_PROVISIONING, PreviewDeployment::STATUS_DEPLOYING, PreviewDeployment::STATUS_READY,
            PreviewDeployment::STATUS_FAILED, 'closed without timestamp',
        ]);
    }

    #[DataProvider('changedReviewedState')]
    public function test_apply_rechecks_changed_configuration_ownership_and_protection(string $change): void
    {
        $fixture = $this->fixture();
        $review = $this->review($fixture);
        $environment = $fixture['environment'];
        match ($change) {
            'runtime' => $environment->update(['build_command' => 'changed-private-command']),
            'process' => $environment->processes()->firstOrFail()->update(['command' => 'changed-private-command']),
            'resource' => $environment->resources()->firstOrFail()->update(['configuration' => ['variables' => ['SECRET' => 'changed-private-secret']]]),
            'variable' => $environment->variables()->firstOrFail()->update(['value' => 'changed-private-secret']),
            'ownership' => ConfigurationOwnership::query()->where('kind', 'processes')->delete(),
            'new manual child' => $environment->processes()->create(['name' => 'new-worker', 'type' => 'worker', 'command' => 'new-private-command', 'replicas' => 1]),
            'protected' => $environment->update(['is_protected' => true]),
            'production' => $environment->update(['type' => 'production']),
        };
        $before = $this->snapshot();
        $this->assertInvalid(fn () => app(ApplicationConfigurationReconciler::class)->apply($review, $fixture['user']));
        $this->assertSame($before, $this->snapshot());
        $this->assertNull($review->fresh()->applied_at);
    }

    public static function changedReviewedState(): array
    {
        return array_map(fn ($change) => [$change], ['runtime', 'process', 'resource', 'variable', 'ownership', 'new manual child', 'protected', 'production']);
    }

    public function test_revoked_access_rejects_removal_even_for_a_previously_authorized_review(): void
    {
        $fixture = $this->fixture();
        $admin = User::factory()->create();
        $organization = $fixture['user']->currentOrganization;
        $organization->members()->attach($admin->id, ['role' => 'admin']);
        $admin->update(['current_organization_id' => $organization->id]);
        $review = app(ApplicationConfigurationReviews::class)->create($fixture['project'], $admin, self::REMOVE, []);
        $organization->members()->detach($admin->id);
        $before = $this->snapshot();
        try {
            app(ApplicationConfigurationReconciler::class)->apply($review, $admin);
            $this->fail('Revoked administrator removed an environment.');
        } catch (AuthorizationException) {
            $this->assertSame($before, $this->snapshot());
            $this->assertNull($review->fresh()->applied_at);
        }
    }

    public function test_expired_removal_review_cannot_delete_records(): void
    {
        $fixture = $this->fixture();
        $review = $this->review($fixture);
        $review->update(['expires_at' => now()->subSecond()]);
        $before = $this->snapshot();
        $this->assertInvalid(fn () => app(ApplicationConfigurationReconciler::class)->apply($review, $fixture['user']), 'review');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_absent_target_and_same_or_new_review_retries_do_not_duplicate_work_or_delete_replacements(): void
    {
        $fixture = $this->fixture();
        $service = app(ApplicationConfigurationReconciler::class);
        $review = $this->review($fixture);
        $receipt = $service->apply($review, $fixture['user']);
        $review->update(['expires_at' => now()->subMinute()]);
        $this->assertSame($receipt->id, $service->apply($review, $fixture['user'])->id);
        $absentReview = $this->review($fixture);
        $this->assertSame('absent', $absentReview->summary['changes'][0]['action']);
        $absentReceipt = $service->apply($absentReview, $fixture['user']);
        $this->assertNotSame($receipt->id, $absentReceipt->id);
        $this->assertSame($absentReceipt->id, $service->apply($absentReview, $fixture['user'])->id);
        $pendingAbsent = $this->review($fixture);
        $replacement = $fixture['project']->environments()->create(['name' => 'Replacement', 'slug' => 'staging', 'type' => 'staging']);
        $this->assertSame($receipt->id, $service->apply($review, $fixture['user'])->id);
        $this->assertInvalid(fn () => $service->apply($pendingAbsent, $fixture['user']));
        $this->assertNotNull($replacement->fresh());
        $this->assertDatabaseCount('configuration_applications', 3);
        $this->assertDatabaseCount('configuration_operations', 0);
        Queue::assertNothingPushed();
    }

    public function test_mixed_create_and_remove_roll_back_all_changes_on_failure_after_actual_deletion(): void
    {
        $fixture = $this->fixture();
        $yaml = self::REMOVE."environments:\n  development:\n    type: development\n    placement: site\n    runtime: {type: php}\n";
        $review = app(ApplicationConfigurationReviews::class)->create($fixture['project'], $fixture['user'], $yaml, ['placements' => ['site' => $fixture['website']->id]]);
        $before = $this->snapshot();
        $dispatcher = Environment::getEventDispatcher();
        Environment::setEventDispatcher(clone $dispatcher);
        $observedDeletion = false;
        try {
            Environment::deleted(function (Environment $environment) use ($fixture, &$observedDeletion): void {
                if ($environment->id === $fixture['environment']->id) {
                    $observedDeletion = true;
                    $this->assertDatabaseMissing('environments', ['id' => $environment->id]);
                    $this->assertDatabaseCount('environment_processes', 0);
                    $this->assertDatabaseCount('environment_variable_versions', 0);
                    $this->assertDatabaseHas('environments', ['slug' => 'development']);
                    throw new RuntimeException('Injected failure after removal');
                }
            });
            try {
                app(ApplicationConfigurationReconciler::class)->apply($review, $fixture['user']);
                $this->fail('Injected removal failure was swallowed.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Injected failure after removal', $exception->getMessage());
            }
        } finally {
            Environment::setEventDispatcher($dispatcher);
        }
        $this->assertTrue($observedDeletion);
        $this->assertSame($before, $this->snapshot());
        $this->assertNull($review->fresh()->applied_at);
        $receipt = app(ApplicationConfigurationReconciler::class)->apply($review, $fixture['user']);
        $this->assertSame('locally_applied', $receipt->status);
        $this->assertDatabaseMissing('environments', ['id' => $fixture['environment']->id]);
        $this->assertDatabaseHas('environments', ['project_id' => $fixture['project']->id, 'slug' => 'development']);
        $this->assertDatabaseCount('configuration_ownerships', 1);
        $this->assertSame('private-secret-value', $fixture['source']->fresh()->value);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    #[DataProvider('activePreviewPlacements')]
    public function test_configuration_cannot_bind_to_a_website_owned_by_an_active_preview(string $status, bool $closed, bool $deploy): void
    {
        $fixture = $this->fixture();
        $fixture['server']->update(['provisioning_status' => Server::STATUS_ACTIVE]);
        $fixture['website']->update(['provisioning_status' => Website::STATUS_ACTIVE]);
        $this->preview($fixture, $status, $closed);
        // A new logical environment must not bypass the preview lifecycle's
        // ownership of the underlying website and mutable repository branch.
        $yaml = "version: 2\nenvironments:\n  development:\n    type: development\n    placement: site\n    runtime: {type: php}\n";
        $bindings = ['placements' => ['site' => $fixture['website']->id]];
        if ($deploy) {
            $yaml .= "    deploy: {repository: app}\n";
            $bindings['repositories'] = ['app' => $fixture['repository']->id];
        }
        $before = $this->snapshot();
        $this->assertInvalid(fn () => app(ApplicationConfigurationPlanner::class)->plan($fixture['project'], $fixture['user'], $yaml, $bindings), 'bindings');
        $this->assertSame($before, $this->snapshot());
        Queue::assertNothingPushed();
    }

    public static function activePreviewPlacements(): array
    {
        $cases = [];
        foreach ([
            [PreviewDeployment::STATUS_PROVISIONING, false], [PreviewDeployment::STATUS_DEPLOYING, false],
            [PreviewDeployment::STATUS_READY, false], [PreviewDeployment::STATUS_FAILED, false],
            [PreviewDeployment::STATUS_CLOSED, false], [PreviewDeployment::STATUS_READY, true],
        ] as [$status, $closed]) {
            foreach ([false, true] as $deploy) {
                $cases[$status.' closed_at='.($closed ? 'set' : 'null').' deploy='.($deploy ? 'yes' : 'no')] = [$status, $closed, $deploy];
            }
        }

        return $cases;
    }

    public function test_closed_preview_placement_is_available_but_reopening_invalidates_a_saved_review(): void
    {
        $fixture = $this->fixture();
        $preview = $this->preview($fixture, PreviewDeployment::STATUS_CLOSED, true);
        $yaml = "version: 2\nenvironments:\n  development:\n    type: development\n    placement: site\n    runtime: {type: php}\n";
        $review = app(ApplicationConfigurationReviews::class)->create($fixture['project'], $fixture['user'], $yaml, ['placements' => ['site' => $fixture['website']->id]]);
        $this->assertSame('create', $review->summary['changes'][0]['action']);
        $preview->update(['status' => PreviewDeployment::STATUS_PROVISIONING, 'closed_at' => null]);
        $before = $this->snapshot();
        $this->assertInvalid(fn () => app(ApplicationConfigurationReconciler::class)->apply($review, $fixture['user']), 'bindings');
        $this->assertSame($before, $this->snapshot());
        $this->assertNull($review->fresh()->applied_at);
    }

    public function test_adoption_cannot_take_an_active_preview_environment_to_another_website(): void
    {
        $fixture = $this->fixture();
        $previewEnvironment = $fixture['project']->environments()->create([
            'name' => 'Preview', 'slug' => 'preview-17', 'type' => 'preview',
            'server_id' => $fixture['server']->id, 'website_id' => $fixture['website']->id,
        ]);
        $preview = $this->preview($fixture, PreviewDeployment::STATUS_READY);
        $preview->update(['environment_id' => $previewEnvironment->id]);
        $cleanWebsite = $fixture['user']->websites()->create([
            'server_id' => $fixture['server']->id, 'name' => 'Clean site', 'url' => 'clean.example', 'description' => 'Test', 'environment' => '',
        ]);
        $yaml = "version: 2\nenvironments:\n  preview-17:\n    adopt: true\n    type: development\n    placement: clean\n    runtime: {type: php}\n";
        $before = $this->snapshot();
        $this->assertInvalid(fn () => app(ApplicationConfigurationPlanner::class)->plan($fixture['project'], $fixture['user'], $yaml, ['placements' => ['clean' => $cleanWebsite->id]]), 'plan');
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($fixture['website']->id, $previewEnvironment->fresh()->website_id);
    }

    public function test_reopening_a_closed_preview_after_removal_review_prevents_deletion(): void
    {
        $fixture = $this->fixture();
        $preview = $this->preview($fixture, PreviewDeployment::STATUS_CLOSED, true);
        $review = $this->review($fixture);
        $preview->update(['status' => PreviewDeployment::STATUS_PROVISIONING, 'closed_at' => null]);
        $before = $this->snapshot();
        $this->assertInvalid(fn () => app(ApplicationConfigurationReconciler::class)->apply($review, $fixture['user']), 'plan');
        $this->assertSame($before, $this->snapshot());
        $this->assertNull($review->fresh()->applied_at);
    }

    private function fixture(): array
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();
        config(['billing.enforce_entitlements' => false]);
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['name' => 'App', 'slug' => 'app', 'created_by' => $user->id]);
        $server = $user->servers()->create(['name' => 'Test', 'public_ip' => '192.0.2.10']);
        $website = $user->websites()->create(['server_id' => $server->id, 'name' => 'Test', 'url' => 'test.example', 'description' => 'Test', 'environment' => '']);
        $provider = $user->providers()->create(['name' => 'GitHub', 'provider' => 'github', 'token' => 'private-provider-token', 'description' => 'Git']);
        $repository = $user->repositories()->create(['provider_id' => $provider->id, 'website_id' => $website->id, 'name' => 'App', 'url' => 'github.com/example/app.git', 'branch' => 'main', 'description' => 'Test']);
        $sourceEnvironment = $project->environments()->create(['name' => 'Secrets', 'slug' => 'secrets', 'type' => 'staging']);
        $source = $sourceEnvironment->variables()->create(['key' => 'SOURCE_TOKEN', 'value' => 'private-secret-value', 'is_secret' => true, 'scope' => 'all', 'current_version' => 1, 'updated_by' => $user->id]);
        $yaml = <<<'YAML'
version: 2
environments:
  staging:
    type: staging
    placement: site
    runtime:
      type: php
      build_command: private-build-command
    processes:
      worker:
        type: worker
        command: private-worker-command
        replicas: 1
    resources:
      cache:
        type: redis
        managed: true
    variables:
      API_TOKEN:
        secret_ref: token
        scope: runtime
YAML;
        $bindings = ['placements' => ['site' => $website->id], 'secrets' => ['token' => $source->id]];
        $initialReview = app(ApplicationConfigurationReviews::class)->create($project, $user, $yaml, $bindings);
        $application = app(ApplicationConfigurationReconciler::class)->apply($initialReview, $user);
        $environment = $project->environments()->where('slug', 'staging')->firstOrFail();

        return compact('user', 'project', 'server', 'website', 'repository', 'source', 'environment', 'application');
    }

    private function plan(array $fixture): array
    {
        return app(ApplicationConfigurationPlanner::class)->plan($fixture['project'], $fixture['user'], self::REMOVE, []);
    }

    private function review(array $fixture): ConfigurationReview
    {
        return app(ApplicationConfigurationReviews::class)->create($fixture['project'], $fixture['user'], self::REMOVE, []);
    }

    private function preview(array $fixture, string $status, bool $closed = false): PreviewDeployment
    {
        return PreviewDeployment::create([
            'project_id' => $fixture['project']->id, 'source_repository_id' => $fixture['repository']->id,
            'environment_id' => $fixture['environment']->id, 'website_id' => $fixture['website']->id,
            'repository_id' => $fixture['repository']->id, 'pull_request_number' => 1,
            'source_branch' => 'feature', 'revision' => str_repeat('a', 40), 'status' => $status,
            'url' => 'preview.example', 'last_activity_at' => now(), 'closed_at' => $closed ? now() : null,
        ]);
    }

    private function assertInvalid(callable $action, ?string $field = null): void
    {
        try {
            $action();
            $this->fail('Unsafe or invalid environment removal was accepted.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
            if ($field !== null) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
            foreach (['private-input', 'private-secret', 'private-command', 'changed-private'] as $sensitive) {
                $this->assertStringNotContainsString($sensitive, json_encode($exception->errors()));
            }
        }
    }

    private function snapshot(?array $tables = null): array
    {
        $snapshot = [];
        foreach ($tables ?? [
            'environments', 'environment_processes', 'environment_resources', 'environment_variables', 'environment_variable_versions',
            'configuration_ownerships', 'configuration_reviews', 'configuration_applications', 'configuration_operations', 'configuration_operation_receipts',
            'servers', 'websites', 'repositories', 'builds', 'preview_deployments',
            'deployment_schedules', 'scaling_schedules', 'scheduled_tasks', 'load_balancers',
        ] as $table) {
            $snapshot[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }
}
