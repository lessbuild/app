<?php

namespace Tests\Modules\Analytics\Feature;

use App\Core\Data\Deletion\ProductDeletionAttempt;
use App\Core\Data\Deletion\ProductDeletionTarget;
use App\Core\Exceptions\Deletion\DeletionBlocked;
use App\Core\Models\DeletionRequest;
use App\Core\Models\DeletionStep;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Modules\Analytics\Actions\Collection\RebuildSiteVisits;
use App\Modules\Analytics\Actions\Goals\RebuildGoalConversions;
use App\Modules\Analytics\Actions\Reporting\RebuildReportAggregates;
use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Jobs\ProcessEventBatch;
use App\Modules\Analytics\Models\IngestionBatch;
use App\Modules\Analytics\Models\Invitation;
use App\Modules\Analytics\Models\ReportExport;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\User;
use App\Modules\Analytics\Models\Workspace;
use App\Modules\Analytics\Services\Deletion\AnalyticsProductDeletionProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Modules\Analytics\RefreshAnalyticsDatabase;
use Tests\TestCase;

/** Authored only: automated execution remains deferred until the full source plan is complete. */
final class DeletionLifecycleTest extends TestCase
{
    use RefreshAnalyticsDatabase;

    public function test_purge_replay_after_native_target_removal_requires_and_accepts_its_matching_receipt(): void
    {
        [$provider, $attempt, $workspace, $request, $step] = $this->workspaceAttempt();

        $this->assertSame('ready', $provider->prepare($attempt)->status);
        $request->forceFill(['phase' => 'purge'])->save();
        $step->forceFill([
            'phase' => 'purge',
            'attempts' => 2,
            'lease_token' => Str::random(48),
            'lease_expires_at' => now()->addMinutes(5),
        ])->save();
        $purgeAttempt = $step->fresh()->attempt();

        $this->assertSame('completed', $provider->purge($purgeAttempt)->status);
        $this->assertDatabaseMissing('workspaces', ['id' => $workspace->getKey()], 'analytics');
        $this->assertSame('completed', $provider->purge($purgeAttempt)->status);
        $this->assertDatabaseHas('analytics_deletion_receipts', [
            'kind' => 'workspace',
            'source_id' => (string) $workspace->getKey(),
            'request_id' => $purgeAttempt->requestId,
            'step_id' => $purgeAttempt->stepId,
            'payload_hash' => $purgeAttempt->payloadHash,
            'phase' => 'purge',
            'status' => 'completed',
        ], 'analytics');

        $staleReplay = new ProductDeletionAttempt(
            $purgeAttempt->requestId,
            $purgeAttempt->stepId,
            $purgeAttempt->target,
            $purgeAttempt->payloadHash,
            $purgeAttempt->generation,
            'stale-lease',
            'purge',
        );
        try {
            $provider->purge($staleReplay);
            $this->fail('A completed native receipt must not bypass current Core attempt authority.');
        } catch (DeletionBlocked $exception) {
            $this->assertSame('deletion_attempt_stale', $exception->reasonCode);
        }
    }

    public function test_missing_native_target_during_purge_cannot_create_a_completion_receipt(): void
    {
        [$provider, $attempt, $workspace, $request, $step] = $this->workspaceAttempt();
        $this->assertSame('ready', $provider->prepare($attempt)->status);
        $workspace->delete();
        $request->forceFill(['phase' => 'purge'])->save();
        $step->forceFill([
            'phase' => 'purge',
            'attempts' => 2,
            'lease_token' => Str::random(48),
            'lease_expires_at' => now()->addMinutes(5),
        ])->save();

        $result = $provider->purge($step->fresh()->attempt());

        $this->assertSame('blocked', $result->status);
        $this->assertSame('native_target_missing', $result->reasonCode);
        $this->assertDatabaseMissing('analytics_deletion_receipts', [
            'kind' => 'workspace',
            'source_id' => (string) $workspace->getKey(),
            'phase' => 'purge',
            'status' => 'completed',
        ], 'analytics');
        $this->assertDatabaseHas('analytics_deletion_tombstones', [
            'kind' => 'workspace',
            'source_id' => (string) $workspace->getKey(),
            'status' => 'prepared',
        ], 'analytics');
    }

    public function test_preparation_terminalizes_queued_ingestion_before_acknowledging_the_fence(): void
    {
        [$provider, $attempt, $workspace] = $this->workspaceAttempt();
        $site = Site::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Pending collection',
            'slug' => 'pending-collection',
            'domains' => ['pending.example.test'],
            'timezone' => 'UTC',
            'verification_token' => Str::random(48),
            'collection_enabled' => true,
        ]);
        $batch = IngestionBatch::query()->create([
            'site_id' => $site->getKey(), 'batch_id' => (string) Str::uuid(), 'event_count' => 1,
            'status' => 'pending', 'accepted_at' => now(),
        ]);

        $this->assertSame('ready', $provider->prepare($attempt)->status);
        $this->assertSame('failed', $batch->fresh()->status);

        (new ProcessEventBatch((int) $batch->getKey()))->handle(
            app(RebuildSiteVisits::class),
            app(RebuildGoalConversions::class),
            app(RebuildReportAggregates::class),
        );

        $this->assertSame('failed', $batch->fresh()->status);
    }

    public function test_account_purge_requires_a_matching_completed_workspace_receipt(): void
    {
        $nativeOwner = User::factory()->create();
        $nativeWorkspace = Workspace::query()->create(['name' => 'Included native workspace']);
        $nativeWorkspace->users()->attach($nativeOwner, ['role' => WorkspaceRole::Owner->value]);
        $coreActor = PlatformUser::query()->create([
            'name' => 'Core account', 'email' => Str::uuid().'@example.test', 'password' => 'unused', 'status' => 'deleting',
        ]);
        $coreWorkspace = CoreWorkspace::query()->create([
            'owner_user_id' => $coreActor->getKey(), 'name' => 'Core workspace', 'slug' => (string) Str::uuid(), 'status' => 'deleting',
        ]);
        $target = new ProductDeletionTarget(
            'analytics', 'account', (string) $nativeOwner->getKey(), (string) $nativeOwner->getKey(),
            (string) $coreActor->getKey(), (string) $coreActor->getKey(), [(string) $nativeWorkspace->getKey()],
        );
        $request = DeletionRequest::query()->create([
            'actor_id' => $coreActor->getKey(), 'kind' => 'account', 'target_id' => $coreActor->getKey(),
            'workspace_ids' => [(string) $coreWorkspace->getKey()], 'identity_bindings' => [],
            'intent_hash' => hash('sha256', 'analytics-account-deletion'), 'receipt_token_hash' => hash('sha256', 'analytics-account-receipt'),
            'idempotency_key' => (string) Str::uuid(), 'phase' => 'prepare', 'status' => 'pending', 'accepted_at' => now(),
        ]);
        $coreWorkspace->forceFill(['settings' => ['deletion_request_id' => (string) $request->getKey()]])->save();
        $workspaceTarget = new ProductDeletionTarget(
            'analytics', 'workspace', (string) $nativeWorkspace->getKey(), (string) $nativeOwner->getKey(),
            (string) $coreWorkspace->getKey(), (string) $coreActor->getKey(),
        );
        $request->steps()->create([
            'product' => 'analytics', 'kind' => 'workspace', 'source_id' => $workspaceTarget->sourceId,
            'target' => $workspaceTarget->toArray(), 'payload_hash' => hash('sha256', json_encode($workspaceTarget->toArray(), JSON_THROW_ON_ERROR)),
            'phase' => 'purge', 'status' => 'completed', 'attempts' => 2, 'lease_token' => Str::random(48),
            'lease_expires_at' => now()->subMinute(),
        ]);
        $accountStep = $request->steps()->create([
            'product' => 'analytics', 'kind' => 'account', 'source_id' => $target->sourceId, 'target' => $target->toArray(),
            'payload_hash' => hash('sha256', json_encode($target->toArray(), JSON_THROW_ON_ERROR)), 'phase' => 'prepare',
            'status' => 'processing', 'attempts' => 1, 'lease_token' => Str::random(48), 'lease_expires_at' => now()->addMinutes(5),
        ]);
        $provider = app(AnalyticsProductDeletionProvider::class);

        $this->assertSame('ready', $provider->prepare($accountStep->attempt())->status);
        $nativeWorkspace->delete();
        $request->forceFill(['phase' => 'purge'])->save();
        $accountStep->forceFill([
            'phase' => 'purge', 'attempts' => 2, 'lease_token' => Str::random(48), 'lease_expires_at' => now()->addMinutes(5),
        ])->save();

        $result = $provider->purge($accountStep->fresh()->attempt());

        $this->assertSame('blocked', $result->status);
        $this->assertSame('workspace_cleanup_incomplete', $result->reasonCode);
        $this->assertDatabaseHas('users', ['id' => $nativeOwner->getKey()], 'analytics');
        $this->assertDatabaseMissing('analytics_deletion_receipts', [
            'kind' => 'account', 'source_id' => (string) $nativeOwner->getKey(), 'phase' => 'purge', 'status' => 'completed',
        ], 'analytics');
    }

    public function test_locked_account_prepare_rechecks_foreign_report_exports_after_preflight(): void
    {
        [$provider, $attempt, $nativeUser, $includedWorkspace] = $this->accountAttempt();
        $this->assertSame([], $provider->inspect($attempt->target)->blockers);
        $foreignOwner = User::factory()->create();
        $foreignWorkspace = Workspace::query()->create(['name' => 'Foreign report workspace']);
        $foreignWorkspace->users()->attach($foreignOwner, ['role' => WorkspaceRole::Owner->value]);
        $foreignSite = $this->makeSite($foreignWorkspace);
        ReportExport::query()->create([
            'workspace_id' => $foreignWorkspace->getKey(), 'site_id' => $foreignSite->getKey(),
            'requested_by' => $nativeUser->getKey(), 'token_hash' => hash('sha256', (string) Str::uuid()),
            'status' => 'completed', 'expires_at' => now()->addDay(),
        ]);

        try {
            $provider->prepare($attempt);
            $this->fail('A newly discovered report export in another workspace must block account fencing.');
        } catch (DeletionBlocked $exception) {
            $this->assertSame('foreign_report_exports', $exception->reasonCode);
        }

        $this->assertDatabaseMissing('analytics_deletion_tombstones', [
            'kind' => 'account', 'source_id' => (string) $nativeUser->getKey(),
        ], 'analytics');
        $this->assertDatabaseHas('workspaces', ['id' => $includedWorkspace->getKey()], 'analytics');
    }

    public function test_locked_account_purge_preserves_foreign_report_rows_when_scope_changes_after_prepare(): void
    {
        [$provider, $attempt, $nativeUser, $includedWorkspace, $request, $accountStep, $workspaceStep, $workspaceTarget] = $this->accountAttempt();
        $this->assertSame('ready', $provider->prepare($attempt)->status);
        $includedWorkspace->delete();
        $workspacePayloadHash = hash('sha256', json_encode($workspaceTarget->toArray(), JSON_THROW_ON_ERROR));
        DB::connection('analytics')->table('analytics_deletion_tombstones')->insert([
            'kind' => 'workspace', 'source_id' => (string) $includedWorkspace->getKey(),
            'canonical_id' => $workspaceTarget->canonicalId, 'request_id' => $request->getKey(),
            'prepare_step_id' => $workspaceStep->getKey(), 'payload_hash' => $workspacePayloadHash,
            'status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('analytics')->table('analytics_deletion_receipts')->insert([
            'kind' => 'workspace', 'source_id' => (string) $includedWorkspace->getKey(),
            'request_id' => $request->getKey(), 'step_id' => $workspaceStep->getKey(),
            'payload_hash' => $workspacePayloadHash, 'phase' => 'purge', 'status' => 'completed',
            'retained' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreignOwner = User::factory()->create();
        $foreignWorkspace = Workspace::query()->create(['name' => 'Foreign report workspace']);
        $foreignWorkspace->users()->attach($foreignOwner, ['role' => WorkspaceRole::Owner->value]);
        $foreignSite = $this->makeSite($foreignWorkspace);
        $foreignExport = ReportExport::query()->create([
            'workspace_id' => $foreignWorkspace->getKey(), 'site_id' => $foreignSite->getKey(),
            'requested_by' => $nativeUser->getKey(), 'token_hash' => hash('sha256', (string) Str::uuid()),
            'status' => 'completed', 'expires_at' => now()->addDay(),
        ]);
        $request->forceFill(['phase' => 'purge'])->save();
        $accountStep->forceFill([
            'phase' => 'purge', 'attempts' => 2, 'lease_token' => Str::random(48), 'lease_expires_at' => now()->addMinutes(5),
        ])->save();

        try {
            $provider->purge($accountStep->fresh()->attempt());
            $this->fail('A report row in another workspace must block the locked account purge.');
        } catch (DeletionBlocked $exception) {
            $this->assertSame('foreign_report_exports', $exception->reasonCode);
        }

        $this->assertDatabaseHas('users', ['id' => $nativeUser->getKey()], 'analytics');
        $this->assertDatabaseHas('report_exports', ['id' => $foreignExport->getKey()], 'analytics');
        $this->assertDatabaseMissing('analytics_deletion_receipts', [
            'kind' => 'account', 'source_id' => (string) $nativeUser->getKey(), 'phase' => 'purge', 'status' => 'completed',
        ], 'analytics');
    }

    public function test_account_purge_removes_own_sessions_tokens_passkeys_and_addressed_invitations_only(): void
    {
        [$provider, $attempt, $nativeUser] = $this->accountAttempt(includeWorkspace: false);
        $workspaceOwner = User::factory()->create();
        $foreignWorkspace = Workspace::query()->create(['name' => 'Unrelated invitation workspace']);
        $foreignWorkspace->users()->attach($workspaceOwner, ['role' => WorkspaceRole::Owner->value]);
        $ownSession = (string) Str::uuid();
        $otherSession = (string) Str::uuid();
        DB::connection('analytics')->table('sessions')->insert([
            ['id' => $ownSession, 'user_id' => $nativeUser->getKey(), 'payload' => 'account session', 'last_activity' => now()->timestamp],
            ['id' => $otherSession, 'user_id' => $workspaceOwner->getKey(), 'payload' => 'other session', 'last_activity' => now()->timestamp],
        ]);
        DB::connection('analytics')->table('password_reset_tokens')->insert([
            'email' => $nativeUser->email, 'token' => 'private-reset-token', 'created_at' => now(),
        ]);
        DB::connection('analytics')->table('passkeys')->insert([
            'user_id' => $nativeUser->getKey(), 'name' => 'Personal key', 'credential_id' => 'credential-'.$nativeUser->getKey(),
            'credential' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $addressedInvitation = Invitation::query()->create([
            'workspace_id' => $foreignWorkspace->getKey(), 'invited_by' => $workspaceOwner->getKey(),
            'email' => strtoupper($nativeUser->email), 'role' => 'viewer', 'token_hash' => hash('sha256', 'addressed'),
            'expires_at' => now()->addDay(),
        ]);
        $otherInvitation = Invitation::query()->create([
            'workspace_id' => $foreignWorkspace->getKey(), 'invited_by' => $nativeUser->getKey(),
            'email' => 'another-person@example.test', 'role' => 'viewer', 'token_hash' => hash('sha256', 'other'),
            'expires_at' => now()->addDay(),
        ]);

        $this->assertSame('ready', $provider->prepare($attempt)->status);
        $request = DeletionRequest::query()->findOrFail($attempt->requestId);
        $step = DeletionStep::query()->findOrFail($attempt->stepId);
        $purgeAttempt = $this->advanceToPurge($request, $step);

        $this->assertSame('completed', $provider->purge($purgeAttempt)->status);
        $this->assertDatabaseMissing('sessions', ['id' => $ownSession], 'analytics');
        $this->assertDatabaseHas('sessions', ['id' => $otherSession, 'user_id' => $workspaceOwner->getKey()], 'analytics');
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $nativeUser->email], 'analytics');
        $this->assertDatabaseMissing('passkeys', ['user_id' => $nativeUser->getKey()], 'analytics');
        $this->assertDatabaseMissing('invitations', ['id' => $addressedInvitation->getKey()], 'analytics');
        $this->assertDatabaseHas('invitations', ['id' => $otherInvitation->getKey(), 'invited_by' => null], 'analytics');
    }

    public function test_purge_manifests_and_removes_an_export_with_a_lost_file_path(): void
    {
        Storage::fake('analytics-local');
        [$provider, $attempt, $workspace, $request, $step] = $this->workspaceAttempt();
        $owner = $workspace->users()->wherePivot('role', WorkspaceRole::Owner->value)->firstOrFail();
        $site = $this->makeSite($workspace);
        $export = ReportExport::query()->create([
            'workspace_id' => $workspace->getKey(), 'site_id' => $site->getKey(), 'requested_by' => $owner->getKey(),
            'token_hash' => hash('sha256', (string) Str::uuid()), 'status' => 'completed',
            'file_path' => null, 'expires_at' => now()->addDay(), 'completed_at' => now(),
        ]);
        $orphanPath = 'exports/'.$export->getKey().'.csv';
        Storage::disk('analytics-local')->put($orphanPath, 'private report contents');

        $this->assertSame('ready', $provider->prepare($attempt)->status);
        $purgeAttempt = $this->advanceToPurge($request, $step);

        $this->assertSame('completed', $provider->purge($purgeAttempt)->status);
        Storage::disk('analytics-local')->assertMissing($orphanPath);
        $this->assertDatabaseMissing('workspaces', ['id' => $workspace->getKey()], 'analytics');
    }

    public function test_partial_export_storage_cleanup_keeps_a_durable_manifest_for_retry(): void
    {
        [$provider, $attempt, $workspace, $request, $step] = $this->workspaceAttempt();
        $owner = $workspace->users()->wherePivot('role', WorkspaceRole::Owner->value)->firstOrFail();
        $site = $this->makeSite($workspace);
        $export = ReportExport::query()->create([
            'workspace_id' => $workspace->getKey(), 'site_id' => $site->getKey(), 'requested_by' => $owner->getKey(),
            'token_hash' => hash('sha256', (string) Str::uuid()), 'status' => 'completed',
            'file_path' => null, 'expires_at' => now()->addDay(), 'completed_at' => now(),
        ]);
        $path = 'exports/'.$export->getKey().'.csv';
        $disk = \Mockery::mock();
        $disk->shouldReceive('files')->with('exports')->twice()->andReturn([]);
        $disk->shouldReceive('delete')->with($path)->twice()->andReturn(false);
        $disk->shouldReceive('exists')->with($path)->twice()->andReturn(true, false);
        Storage::shouldReceive('disk')->with('analytics-local')->andReturn($disk);

        $this->assertSame('ready', $provider->prepare($attempt)->status);
        $purgeAttempt = $this->advanceToPurge($request, $step);

        $firstAttempt = $provider->purge($purgeAttempt);
        $this->assertSame('waiting', $firstAttempt->status);
        $this->assertSame('export_file_cleanup_pending', $firstAttempt->reasonCode);
        $this->assertDatabaseHas('analytics_deletion_files', ['path' => $path, 'status' => 'pending'], 'analytics');
        $this->assertDatabaseHas('workspaces', ['id' => $workspace->getKey()], 'analytics');

        $this->assertSame('completed', $provider->purge($purgeAttempt)->status);
        $this->assertDatabaseHas('analytics_deletion_files', ['path' => $path, 'status' => 'deleted'], 'analytics');
    }

    private function advanceToPurge(DeletionRequest $request, DeletionStep $step): ProductDeletionAttempt
    {
        $request->forceFill(['phase' => 'purge'])->save();
        $step->forceFill([
            'phase' => 'purge', 'attempts' => $step->attempts + 1,
            'lease_token' => Str::random(48), 'lease_expires_at' => now()->addMinutes(5),
        ])->save();

        return $step->fresh()->attempt();
    }

    private function makeSite(Workspace $workspace): Site
    {
        return Site::query()->create([
            'workspace_id' => $workspace->getKey(), 'name' => 'Export site', 'slug' => 'export-site',
            'domains' => ['export.example.test'], 'timezone' => 'UTC', 'verification_token' => Str::random(48),
            'collection_enabled' => true,
        ]);
    }

    /** @return array{AnalyticsProductDeletionProvider, ProductDeletionAttempt, User, ?Workspace, ?DeletionRequest, ?DeletionStep, ?DeletionStep, ?ProductDeletionTarget} */
    private function accountAttempt(bool $includeWorkspace = true): array
    {
        $nativeUser = User::factory()->create();
        $nativeWorkspace = null;
        $coreWorkspace = null;
        $coreWorkspaceStep = null;
        $workspaceTarget = null;
        $nativeWorkspaceIds = [];
        $coreWorkspaceIds = [];
        $coreActor = PlatformUser::query()->create([
            'name' => 'Core account', 'email' => Str::uuid().'@example.test', 'password' => 'unused', 'status' => 'deleting',
        ]);

        if ($includeWorkspace) {
            $nativeWorkspace = Workspace::query()->create(['name' => 'Account owned workspace']);
            $nativeWorkspace->users()->attach($nativeUser, ['role' => WorkspaceRole::Owner->value]);
            $nativeWorkspaceIds[] = (string) $nativeWorkspace->getKey();
            $coreWorkspace = CoreWorkspace::query()->create([
                'owner_user_id' => $coreActor->getKey(), 'name' => 'Core account workspace', 'slug' => (string) Str::uuid(), 'status' => 'deleting',
            ]);
            $coreWorkspaceIds[] = (string) $coreWorkspace->getKey();
        }
        $request = DeletionRequest::query()->create([
            'actor_id' => $coreActor->getKey(), 'kind' => 'account', 'target_id' => $coreActor->getKey(),
            'workspace_ids' => $coreWorkspaceIds, 'identity_bindings' => [],
            'intent_hash' => hash('sha256', 'analytics-account-attempt'), 'receipt_token_hash' => hash('sha256', 'analytics-account-token'),
            'idempotency_key' => (string) Str::uuid(), 'phase' => 'prepare', 'status' => 'pending', 'accepted_at' => now(),
        ]);
        if ($coreWorkspace !== null && $nativeWorkspace !== null) {
            $coreWorkspace->forceFill(['settings' => ['deletion_request_id' => (string) $request->getKey()]])->save();
            $workspaceTarget = new ProductDeletionTarget(
                'analytics', 'workspace', (string) $nativeWorkspace->getKey(), (string) $nativeUser->getKey(),
                (string) $coreWorkspace->getKey(), (string) $coreActor->getKey(),
            );
            $coreWorkspaceStep = $request->steps()->create([
                'product' => 'analytics', 'kind' => 'workspace', 'source_id' => $workspaceTarget->sourceId,
                'target' => $workspaceTarget->toArray(),
                'payload_hash' => hash('sha256', json_encode($workspaceTarget->toArray(), JSON_THROW_ON_ERROR)),
                'phase' => 'prepare', 'status' => 'completed', 'attempts' => 1,
                'lease_token' => Str::random(48), 'lease_expires_at' => now()->addMinutes(5),
            ]);
        }
        $target = new ProductDeletionTarget(
            'analytics', 'account', (string) $nativeUser->getKey(), (string) $nativeUser->getKey(),
            (string) $coreActor->getKey(), (string) $coreActor->getKey(), $nativeWorkspaceIds,
        );
        $accountStep = $request->steps()->create([
            'product' => 'analytics', 'kind' => 'account', 'source_id' => $target->sourceId, 'target' => $target->toArray(),
            'payload_hash' => hash('sha256', json_encode($target->toArray(), JSON_THROW_ON_ERROR)), 'phase' => 'prepare',
            'status' => 'processing', 'attempts' => 1, 'lease_token' => Str::random(48), 'lease_expires_at' => now()->addMinutes(5),
        ]);

        return [app(AnalyticsProductDeletionProvider::class), $accountStep->attempt(), $nativeUser, $nativeWorkspace,
            $request, $accountStep, $coreWorkspaceStep, $workspaceTarget];
    }

    /** @return array{AnalyticsProductDeletionProvider, ProductDeletionAttempt, Workspace, DeletionRequest, DeletionStep} */
    private function workspaceAttempt(): array
    {
        $nativeOwner = User::factory()->create();
        $nativeWorkspace = Workspace::query()->create(['name' => 'Deletion lifecycle']);
        $nativeWorkspace->users()->attach($nativeOwner, ['role' => WorkspaceRole::Owner->value]);
        $coreActor = PlatformUser::query()->create([
            'name' => 'Core actor', 'email' => Str::uuid().'@example.test', 'password' => 'unused', 'status' => 'active',
        ]);
        $coreWorkspace = CoreWorkspace::query()->create([
            'owner_user_id' => $coreActor->getKey(), 'name' => 'Core workspace', 'slug' => (string) Str::uuid(), 'status' => 'deleting',
        ]);
        $target = new ProductDeletionTarget(
            'analytics', 'workspace', (string) $nativeWorkspace->getKey(), (string) $nativeOwner->getKey(),
            (string) $coreWorkspace->getKey(), (string) $coreActor->getKey(),
        );
        $request = DeletionRequest::query()->create([
            'actor_id' => $coreActor->getKey(), 'kind' => 'workspace', 'target_id' => $coreWorkspace->getKey(),
            'workspace_ids' => [(string) $coreWorkspace->getKey()], 'identity_bindings' => [],
            'intent_hash' => hash('sha256', 'analytics-deletion'), 'receipt_token_hash' => hash('sha256', 'analytics-receipt'),
            'idempotency_key' => (string) Str::uuid(), 'phase' => 'prepare', 'status' => 'pending', 'accepted_at' => now(),
        ]);
        $coreWorkspace->forceFill(['settings' => ['deletion_request_id' => (string) $request->getKey()]])->save();
        $step = $request->steps()->create([
            'product' => 'analytics', 'kind' => 'workspace', 'source_id' => $target->sourceId, 'target' => $target->toArray(),
            'payload_hash' => hash('sha256', json_encode($target->toArray(), JSON_THROW_ON_ERROR)), 'phase' => 'prepare',
            'status' => 'processing', 'attempts' => 1, 'lease_token' => Str::random(48), 'lease_expires_at' => now()->addMinutes(5),
        ]);

        return [app(AnalyticsProductDeletionProvider::class), $step->attempt(), $nativeWorkspace, $request, $step];
    }
}
