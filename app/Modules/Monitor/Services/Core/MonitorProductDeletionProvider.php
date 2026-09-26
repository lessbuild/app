<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Contracts\ProductDeletionProvider;
use App\Core\Data\Deletion\ProductDeletionAttempt;
use App\Core\Data\Deletion\ProductDeletionPreview;
use App\Core\Data\Deletion\ProductDeletionResult;
use App\Core\Data\Deletion\ProductDeletionTarget;
use App\Core\Services\Deletion\DeletionAuthority;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MonitorProductDeletionProvider implements ProductDeletionProvider
{
    public function product(): string
    {
        return 'monitor';
    }

    public function inspect(ProductDeletionTarget $target): ProductDeletionPreview
    {
        if (! $this->validTarget($target)) {
            return new ProductDeletionPreview(['target_invalid']);
        }

        if ($target->kind === 'workspace') {
            return $this->inspectWorkspace($target);
        }

        return $this->inspectAccount($target);
    }

    public function prepare(ProductDeletionAttempt $attempt): ProductDeletionResult
    {
        if ($attempt->phase !== 'prepare' || $attempt->target->product !== $this->product()) {
            return new ProductDeletionResult('blocked', 'phase_invalid');
        }

        app(DeletionAuthority::class)->assertAttempt($attempt);

        return DB::connection('monitor')->transaction(function () use ($attempt): ProductDeletionResult {
            $target = $attempt->target;
            $this->reserveSourceWriteLock($target);
            app(DeletionAuthority::class)->assertAttempt($attempt, lock: true);
            $receipt = $this->receipt($target);
            if ($receipt !== null) {
                return $this->matchesReceipt($receipt, $attempt)
                    ? new ProductDeletionResult('ready')
                    : new ProductDeletionResult('blocked', 'receipt_conflict');
            }

            $existing = DB::connection('monitor')->table('product_deletion_fences')
                ->where('kind', $target->kind)->where('source_id', $target->sourceId)->lockForUpdate()->first();
            if ($existing !== null) {
                if (! $this->matchesFence($existing, $attempt) || $existing->status !== 'prepared') {
                    return new ProductDeletionResult('blocked', 'fence_conflict');
                }
                $blockers = $this->inspect($target)->blockers;
                if ($blockers !== []) {
                    return new ProductDeletionResult('blocked', $blockers[0]);
                }

                return $this->drainResult($target);
            }

            $blockers = $this->inspect($target)->blockers;
            if ($blockers !== []) {
                return new ProductDeletionResult('blocked', $blockers[0]);
            }

            $now = now('UTC');
            DB::connection('monitor')->table('product_deletion_fences')->insert([
                'kind' => $target->kind,
                'source_id' => $target->sourceId,
                'request_id' => $attempt->requestId,
                'step_id' => $attempt->stepId,
                'payload_hash' => $attempt->payloadHash,
                'target' => json_encode($target->toArray(), JSON_THROW_ON_ERROR),
                'status' => 'prepared',
                'prepared_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $this->drainResult($target);
        }, attempts: 3);
    }

    public function purge(ProductDeletionAttempt $attempt): ProductDeletionResult
    {
        if ($attempt->phase !== 'purge' || $attempt->target->product !== $this->product()) {
            return new ProductDeletionResult('blocked', 'phase_invalid');
        }

        app(DeletionAuthority::class)->assertAttempt($attempt);

        return DB::connection('monitor')->transaction(function () use ($attempt): ProductDeletionResult {
            $target = $attempt->target;
            $this->reserveSourceWriteLock($target);
            app(DeletionAuthority::class)->assertAttempt($attempt, lock: true);
            $receipt = $this->receipt($target);
            if ($receipt !== null) {
                return $this->matchesReceipt($receipt, $attempt)
                    ? new ProductDeletionResult('completed')
                    : new ProductDeletionResult('blocked', 'receipt_conflict');
            }

            $fence = DB::connection('monitor')->table('product_deletion_fences')
                ->where('kind', $target->kind)->where('source_id', $target->sourceId)->lockForUpdate()->first();
            if ($fence === null || ! $this->matchesFence($fence, $attempt) || $fence->status !== 'prepared') {
                return new ProductDeletionResult('blocked', 'fence_missing');
            }

            $drain = $this->drainResult($target);
            if ($drain->status !== 'ready') {
                return $drain;
            }

            if ($target->kind === 'workspace' && ! Workspace::query()->whereKey($target->sourceId)->exists()) {
                return new ProductDeletionResult('blocked', 'source_missing');
            }
            if ($target->kind === 'account' && ! User::query()->whereKey($target->sourceId)->exists()) {
                return new ProductDeletionResult('blocked', 'source_missing');
            }
            if ($target->kind === 'workspace') {
                $blockers = $this->inspectWorkspace($target)->blockers;
                if ($blockers !== []) {
                    return new ProductDeletionResult('blocked', $blockers[0]);
                }
            } else {
                $user = User::query()->whereKey($target->sourceId)->first();
                if ($user->workspaces()->exists() || Workspace::query()->where('owner_id', $user->getKey())->exists()) {
                    return new ProductDeletionResult('blocked', 'account_memberships_remain');
                }
            }

            app(DeletionAuthority::class)->assertAttempt($attempt, lock: true);
            $this->purgeTarget($target);
            $now = now('UTC');
            DB::connection('monitor')->table('product_deletion_receipts')->insert([
                'kind' => $target->kind,
                'source_id' => $target->sourceId,
                'request_id' => $attempt->requestId,
                'step_id' => $attempt->stepId,
                'payload_hash' => $attempt->payloadHash,
                'completed_at' => $now,
            ]);
            DB::connection('monitor')->table('product_deletion_fences')
                ->where('id', $fence->id)->update(['status' => 'completed', 'completed_at' => $now, 'updated_at' => $now]);

            // Revalidate Core's accepted intent after the destructive work and receipts
            // have been written, immediately before the native transaction commits.
            app(DeletionAuthority::class)->assertAttempt($attempt, lock: true);

            return new ProductDeletionResult('completed');
        }, attempts: 3);
    }

    private function inspectWorkspace(ProductDeletionTarget $target): ProductDeletionPreview
    {
        $workspace = Workspace::query()->whereKey($target->sourceId)->first();
        if ($workspace === null) {
            return new ProductDeletionPreview(['source_missing']);
        }

        $blockers = [];
        if ((string) $workspace->owner_id !== $target->actorSourceId) {
            $blockers[] = 'owner_changed';
        }
        if (! $workspace->members()->where('users.id', $workspace->owner_id)->wherePivot('role', 'owner')->exists()) {
            $blockers[] = 'owner_membership_changed';
        }
        $foreignMembers = $workspace->members()->where('users.id', '!=', $workspace->owner_id)->exists();
        if ($foreignMembers) {
            $blockers[] = 'workspace_has_other_members';
        }
        if ($this->hasUnsettledBilling($workspace)) {
            $blockers[] = 'billing_unsettled';
        }

        return new ProductDeletionPreview($blockers, ['Stripe event IDs retained for webhook deduplication after event details are scrubbed.'], [
            'applications' => Application::withTrashed()->where('workspace_id', $workspace->getKey())->count(),
            'members' => $workspace->members()->count(),
        ]);
    }

    private function inspectAccount(ProductDeletionTarget $target): ProductDeletionPreview
    {
        $user = User::query()->whereKey($target->sourceId)->first();
        if ($user === null) {
            return new ProductDeletionPreview(['source_missing']);
        }

        $workspaceIds = array_values(array_unique(array_map('strval', $target->sourceWorkspaceIds)));
        $ownedIds = Workspace::query()->where('owner_id', $user->getKey())->pluck('id')->map(strval(...))->all();
        sort($ownedIds);
        $includedOwnedIds = $workspaceIds;
        sort($includedOwnedIds);
        $blockers = [];
        if ((string) $user->getKey() !== $target->actorSourceId || $ownedIds !== $includedOwnedIds) {
            $blockers[] = 'account_ownership_changed';
        }
        if ($user->workspaces()->whereNotIn('workspaces.id', $workspaceIds)->exists()) {
            $blockers[] = 'account_has_foreign_memberships';
        }
        foreach (Workspace::query()->whereIn('id', $workspaceIds)->orderBy('id')->get() as $workspace) {
            if ((string) $workspace->owner_id !== (string) $user->getKey()) {
                $blockers[] = 'account_workspace_owner_changed';
            }
            if ($workspace->members()->where('users.id', '!=', $user->getKey())->exists()) {
                $blockers[] = 'workspace_has_other_members';
            }
            if ($this->hasUnsettledBilling($workspace)) {
                $blockers[] = 'billing_unsettled';
            }
        }

        return new ProductDeletionPreview(array_values(array_unique($blockers)), ['Stripe event IDs retained for webhook deduplication after event details are scrubbed.'], [
            'owned_workspaces' => count($ownedIds),
            'memberships' => $user->workspaces()->count(),
        ]);
    }

    private function hasUnsettledBilling(Workspace $workspace): bool
    {
        $billingStatus = strtolower((string) $workspace->billing_status);
        if (in_array($billingStatus, ['active', 'trialing', 'past_due', 'unpaid', 'incomplete', 'pending'], true)
            || (filled($workspace->stripe_subscription_id) && ! in_array($billingStatus, ['inactive', 'canceled', 'cancelled', 'deleted', 'incomplete_expired'], true))) {
            return true;
        }
        if (filled($workspace->stripe_checkout_session_id) || $workspace->billing_checkout_started_at !== null) {
            return true;
        }

        return $workspace->billingEvents()->where(function ($query): void {
            $query->whereNull('processed_at')->orWhereNotIn('processing_status', ['applied', 'ignored']);
        })->exists();
    }

    /**
     * Existing recovery bounds are 120s for telemetry, alert, and probe leases, 15m for digest/usage sends,
     * and 10m for incident outbox claims. Expired claims block cleanup because send outcomes can be unknown.
     */
    private function drainResult(ProductDeletionTarget $target): ProductDeletionResult
    {
        $workspaceIds = $target->kind === 'workspace' ? [$target->sourceId] : $target->sourceWorkspaceIds;
        foreach ($workspaceIds as $workspaceId) {
            $receipt = DB::connection('monitor')->table('ingest_receipts')->where('workspace_id', $workspaceId)->where('status', 'processing')->first();
            if ($receipt !== null) {
                return $receipt->next_attempt_at === null || $receipt->next_attempt_at <= now('UTC')->format('Y-m-d H:i:s.u')
                    ? new ProductDeletionResult('blocked', 'telemetry_claim_unresolved')
                    : new ProductDeletionResult('waiting', 'telemetry_claim_in_flight');
            }

            $alert = DB::connection('monitor')->table('alert_deliveries')->where('workspace_id', $workspaceId)->where('status', 'sending')->first();
            if ($alert !== null) {
                return $alert->next_attempt_at === null || $alert->next_attempt_at <= now('UTC')->format('Y-m-d H:i:s.u')
                    ? new ProductDeletionResult('blocked', 'delivery_claim_unresolved')
                    : new ProductDeletionResult('waiting', 'delivery_claim_in_flight');
            }

            foreach (['issue_digest_deliveries', 'usage_alert_deliveries'] as $table) {
                $notification = DB::connection('monitor')->table($table)->where('workspace_id', $workspaceId)->where('status', 'sending')->first();
                if ($notification !== null) {
                    return $notification->sending_started_at === null
                        || $notification->sending_started_at <= now('UTC')->subMinutes(15)->format('Y-m-d H:i:s.u')
                        ? new ProductDeletionResult('blocked', 'notification_claim_unresolved')
                        : new ProductDeletionResult('waiting', 'notification_claim_in_flight');
                }
            }

            $outbox = DB::connection('monitor')->table('project_connection_incident_outbox_events')->where('status', 'processing')
                ->whereIn('source_environment_id', function ($query) use ($workspaceId): void {
                    $query->select('environments.id')->from('environments')
                        ->join('applications', 'applications.id', '=', 'environments.application_id')
                        ->where('applications.workspace_id', $workspaceId);
                })->first();
            if ($outbox !== null) {
                return $outbox->updated_at === null || $outbox->updated_at <= now('UTC')->subMinutes(10)->format('Y-m-d H:i:s.u')
                    ? new ProductDeletionResult('blocked', 'outbox_claim_unresolved')
                    : new ProductDeletionResult('waiting', 'outbox_claim_in_flight');
            }

            $check = DB::connection('monitor')->table('monitor_checks')->join('monitors', 'monitors.id', '=', 'monitor_checks.monitor_id')
                ->join('environments', 'environments.id', '=', 'monitors.environment_id')
                ->join('applications', 'applications.id', '=', 'environments.application_id')
                ->where('applications.workspace_id', $workspaceId)->where('monitor_checks.status', 'running')->first(['monitor_checks.lease_until']);
            if ($check !== null) {
                return $check->lease_until === null || $check->lease_until <= now('UTC')->format('Y-m-d H:i:s.u')
                    ? new ProductDeletionResult('blocked', 'probe_claim_unresolved')
                    : new ProductDeletionResult('waiting', 'probe_claim_in_flight');
            }
        }

        return new ProductDeletionResult('ready');
    }

    private function purgeTarget(ProductDeletionTarget $target): void
    {
        if ($target->kind === 'workspace') {
            $this->purgeWorkspace($target->sourceId);

            return;
        }

        $user = User::query()->whereKey($target->sourceId)->lockForUpdate()->first();
        if ($user === null) {
            throw (new ModelNotFoundException)->setModel(User::class, [$target->sourceId]);
        }
        if ($user->workspaces()->exists() || Workspace::query()->where('owner_id', $user->getKey())->exists()) {
            throw new \RuntimeException('Monitor account still owns or belongs to a workspace.');
        }
        DB::connection('monitor')->table('sessions')->where('user_id', $user->getKey())->delete();
        $this->purgeOperationReceipts('actor_source_id', (string) $user->getKey());
        DB::connection('monitor')->table('password_reset_tokens')->where('email', $user->email)->delete();
        $user->delete();
    }

    private function purgeWorkspace(string $workspaceId): void
    {
        $this->purgeOperationReceipts('workspace_source_id', $workspaceId);
        $workspace = Workspace::query()->whereKey($workspaceId)->lockForUpdate()->first();
        if ($workspace === null) {
            throw (new ModelNotFoundException)->setModel(Workspace::class, [$workspaceId]);
        }

        $applicationIds = Application::withTrashed()->where('workspace_id', $workspaceId)->pluck('id');
        if ($applicationIds->isNotEmpty()) {
            $incidentIds = DB::connection('monitor')->table('incidents')->whereIn('application_id', $applicationIds)->pluck('id');
            $deploymentIds = DB::connection('monitor')->table('deployments')->whereIn('application_id', $applicationIds)->pluck('id');
            DB::connection('monitor')->table('project_connection_event_receipts')->whereIn('deployment_id', $deploymentIds)->delete();
            DB::connection('monitor')->table('resource_restoration_receipts')->whereIn('application_id', $applicationIds)->delete();
            DB::connection('monitor')->table('project_connection_incident_outbox_events')->whereIn('source_incident_id', $incidentIds->map(strval(...)))->delete();
            Application::withTrashed()->whereIn('id', $applicationIds)->forceDelete();
        }

        DB::connection('monitor')->table('billing_events')->where('workspace_id', $workspaceId)->update([
            'workspace_id' => null,
            'event_type' => 'retained',
            'stripe_created_at' => null,
            'processing_status' => 'ignored',
            'ignored_reason' => 'workspace_deleted',
            'updated_at' => now('UTC'),
        ]);
        $workspace->delete();
    }

    private function purgeOperationReceipts(string $column, string $id): void
    {
        foreach (['credential_mutation_receipts', 'blueprint_application_receipts'] as $table) {
            if (Schema::connection('monitor')->hasTable($table)) {
                DB::connection('monitor')->table($table)->where($column, $id)->delete();
            }
        }
    }

    private function validTarget(ProductDeletionTarget $target): bool
    {
        if ($target->product !== $this->product() || ! in_array($target->kind, ['workspace', 'account'], true)
            || $target->sourceId === '' || $target->actorSourceId === '') {
            return false;
        }

        return $target->kind !== 'account' || count($target->sourceWorkspaceIds) === count(array_unique(array_map('strval', $target->sourceWorkspaceIds)));
    }

    private function reserveSourceWriteLock(ProductDeletionTarget $target): void
    {
        $table = $target->kind === 'workspace' ? 'workspaces' : 'users';
        DB::connection('monitor')->table($table)->where('id', $target->sourceId)->update(['id' => DB::raw('id')]);

        if ($target->kind === 'account') {
            DB::connection('monitor')->table('workspaces')->whereIn('id', $target->sourceWorkspaceIds)
                ->update(['id' => DB::raw('id')]);
        }
    }

    private function receipt(ProductDeletionTarget $target): ?object
    {
        return DB::connection('monitor')->table('product_deletion_receipts')
            ->where('kind', $target->kind)->where('source_id', $target->sourceId)->first();
    }

    private function matchesReceipt(object $receipt, ProductDeletionAttempt $attempt): bool
    {
        return $receipt->request_id === $attempt->requestId && $receipt->step_id === $attempt->stepId
            && hash_equals($receipt->payload_hash, $attempt->payloadHash);
    }

    private function matchesFence(object $fence, ProductDeletionAttempt $attempt): bool
    {
        return $fence->request_id === $attempt->requestId && $fence->step_id === $attempt->stepId
            && hash_equals($fence->payload_hash, $attempt->payloadHash);
    }
}
