<?php

namespace App\Core\Services\Deletion;

use App\Core\Exceptions\Deletion\DeletionBlocked;
use App\Core\Models\DeletionRequest;
use App\Core\Models\PlatformAuthSession;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectConnection;
use App\Core\Models\ProjectMembership;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\Auth\PlatformAuthenticationSessions;
use App\Core\Services\Auth\VerifyPlatformTwoFactorCode;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RequestDeletion
{
    public function __construct(private readonly DeletionPlanner $planner, private readonly Hasher $hasher, private readonly VerifyPlatformTwoFactorCode $twoFactor) {}

    public function request(PlatformUser $actor, ?Workspace $workspace, array $input, string $receiptToken, string $sessionId): DeletionRequest
    {
        $existing = DeletionRequest::query()->where('idempotency_key', $input['idempotency_key'] ?? '')->first();
        if ($existing !== null) {
            if ((string) $existing->actor_id !== (string) $actor->getKey()
                || $existing->kind !== ($workspace === null ? 'account' : 'workspace')
                || (string) $existing->target_id !== (string) ($workspace?->getKey() ?? $actor->getKey())
                || ! hash_equals($existing->intent_hash, (string) ($input['fingerprint'] ?? ''))
                || ! hash_equals($existing->receipt_token_hash, hash('sha256', $receiptToken))) {
                throw new DeletionBlocked('deletion_confirmation_invalid');
            }

            return $existing;
        }
        $plan = $this->planner->plan($actor, $workspace);
        if ($plan['blockers'] !== []) {
            throw new DeletionBlocked($plan['blockers'][0]);
        }
        if (! hash_equals($plan['fingerprint'], (string) ($input['fingerprint'] ?? ''))) {
            throw new DeletionBlocked('deletion_preview_changed');
        }
        if (! Str::isUuid($input['idempotency_key'] ?? '') || ! preg_match('/\A[a-f0-9]{64}\z/', $receiptToken)) {
            throw new DeletionBlocked('deletion_confirmation_invalid');
        }

        return DB::connection('core')->transaction(function () use ($actor, $workspace, $input, $receiptToken, $sessionId, $plan): DeletionRequest {
            DB::connection('core')->table('users')->where('id', $actor->getKey())->update(['id' => DB::raw('id')]);
            $actor = PlatformUser::query()->lockForUpdate()->findOrFail($actor->getKey());
            $session = PlatformAuthSession::query()->whereKey($sessionId)->where('user_id', $actor->getKey())->whereNull('revoked_at')->lockForUpdate()->first();
            if ($actor->status !== 'active' || $session === null) {
                throw new DeletionBlocked('account_session_changed');
            }
            if (! $actor->hasPassword() && ! $actor->twoFactorEnabled() && $session->created_at->lt(now()->subMinutes(10))) {
                throw new DeletionBlocked('fresh_sign_in_required');
            }
            if ($this->planner->projectionInProgress((string) $actor->getKey(), $plan['intent']['workspaces'])) {
                throw new DeletionBlocked('identity_projection_in_progress');
            }
            if ($actor->hasPassword() && ! $this->hasher->check($input['current_password'] ?? '', $actor->password)) {
                throw ValidationException::withMessages(['current_password' => __('The current password is incorrect.')]);
            }
            if ($actor->twoFactorEnabled() && ! $this->twoFactor->handle($actor, $input['code'] ?? '')) {
                throw ValidationException::withMessages(['code' => __('The authentication or recovery code is invalid.')]);
            }
            $workspaces = Workspace::query()->whereIn('id', $plan['intent']['workspaces'])->orderBy('id')->lockForUpdate()->get();
            if ($workspaces->count() !== count($plan['intent']['workspaces'])) {
                throw new DeletionBlocked('deletion_preview_changed');
            }
            foreach ($workspaces as $owned) {
                $this->planner->assertOwned($actor, $owned);
                if (! in_array($owned->status, ['active', 'archived'], true)
                    || $owned->memberships()->where('user_id', '!=', $actor->getKey())->whereNotIn('status', ['revoked', 'removed'])->lockForUpdate()->get()->isNotEmpty()
                    || $this->planner->billingBlockers((string) $owned->getKey()) !== []) {
                    throw new DeletionBlocked('deletion_preview_changed');
                }
            }
            if ($workspace === null) {
                $currentOwned = Workspace::query()->where('owner_user_id', $actor->getKey())->where('status', '!=', 'deleted')->orderBy('id')->pluck('id')->all();
                if ($currentOwned !== $plan['intent']['workspaces']
                    || WorkspaceMembership::query()->where('user_id', $actor->getKey())->whereNotIn('status', ['revoked', 'removed'])
                        ->whereHas('workspace', fn ($query) => $query->where('owner_user_id', '!=', $actor->getKey())->where('status', '!=', 'deleted'))->exists()) {
                    throw new DeletionBlocked('deletion_preview_changed');
                }
            }
            $confirmation = $workspace === null ? (string) $actor->email : (string) $workspaces->first()?->name;
            if (! hash_equals($confirmation, (string) ($input['confirmation'] ?? '')) || ! ($input['understood'] ?? false)) {
                throw new DeletionBlocked('deletion_confirmation_invalid');
            }
            if ($this->planner->bindings((string) $actor->getKey(), $plan['intent']['workspaces'], lock: true) !== $plan['intent']['bindings']) {
                throw new DeletionBlocked('deletion_preview_changed');
            }
            $request = DeletionRequest::query()->create([
                'actor_id' => $actor->getKey(), 'kind' => $plan['intent']['kind'], 'target_id' => $plan['intent']['target'],
                'workspace_ids' => $plan['intent']['workspaces'], 'identity_bindings' => $plan['intent']['bindings'],
                'intent_hash' => $plan['fingerprint'], 'receipt_token_hash' => hash('sha256', $receiptToken),
                'idempotency_key' => $input['idempotency_key'], 'phase' => 'prepare', 'status' => 'pending',
                'retained' => $plan['retained'], 'accepted_at' => now(),
            ]);
            foreach ($plan['targets'] as $target) {
                $request->steps()->create(['product' => $target->product, 'kind' => $target->kind, 'source_id' => $target->sourceId,
                    'target' => $target->toArray(), 'payload_hash' => hash('sha256', json_encode($target->toArray(), JSON_THROW_ON_ERROR)),
                    'phase' => 'prepare', 'status' => 'pending', 'attempts' => 0, 'available_at' => now()]);
            }
            foreach ($workspaces as $owned) {
                $owned->forceFill(['status' => 'deleting', 'settings' => array_merge($owned->settings ?? [], ['deletion_request_id' => (string) $request->getKey()])])->save();
                $owned->invitations()->where('status', 'pending')->update(['status' => 'revoked', 'updated_at' => now()]);
            }
            $memberships = WorkspaceMembership::query()->whereIn('workspace_id', $plan['intent']['workspaces']);
            WorkspaceProductAccess::query()->whereIn('membership_id', (clone $memberships)->select('id'))
                ->update(['status' => 'revoked', 'revoked_at' => now(), 'updated_at' => now()]);
            $memberships->update(['status' => 'revoked', 'revoked_at' => now(), 'updated_at' => now()]);
            $projects = Project::query()->whereIn('workspace_id', $plan['intent']['workspaces']);
            ProjectMembership::query()->whereIn('project_id', (clone $projects)->select('id'))
                ->update(['status' => 'revoked', 'revoked_at' => now(), 'updated_at' => now()]);
            ProjectConnection::query()->whereIn('project_id', (clone $projects)->select('id'))
                ->update(['status' => 'disconnected', 'disconnected_at' => now(), 'updated_at' => now()]);
            $projects->update(['status' => 'deleting', 'updated_at' => now()]);
            if ($workspace === null) {
                $actor->forceFill(['status' => 'deleting', 'remember_token' => null])->save();
                app(PlatformAuthenticationSessions::class)->revokeAll($actor);
                DB::connection('core')->table('platform_sso_tickets')->where('user_id', $actor->getKey())->delete();
            }

            return $request;
        }, attempts: 3);
    }
}
