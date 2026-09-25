<?php

namespace App\Core\Services\Auth;

use App\Core\Models\PlatformUser;
use App\Core\Models\WorkspaceFeedback;
use Illuminate\Support\Facades\DB;

final class ExportPlatformAccountData
{
    /**
     * Export Core-owned personal and access data without credentials or workspace secrets.
     * Product operational records remain available from their product-owned export tools.
     *
     * @return array<string, mixed>
     */
    public function handle(PlatformUser $user): array
    {
        $userId = (string) $user->getKey();
        $core = DB::connection('core');

        return [
            'format' => 'buildpusher.platform-account.v1',
            'exported_at' => now()->utc()->toIso8601String(),
            'account' => [
                'id' => $userId,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'status' => $user->status,
                'created_at' => $user->created_at?->toIso8601String(),
                'updated_at' => $user->updated_at?->toIso8601String(),
                'preferences' => $user->preferences ?? [],
                'password_configured' => $user->hasPassword(),
                'two_factor_enabled' => $user->twoFactorEnabled(),
            ],
            'linked_accounts' => $core->table('user_identities')
                ->where('user_id', $userId)
                ->orderBy('provider')
                ->get(['provider', 'provider_user_id', 'provider_email', 'verified_at', 'status', 'created_at'])
                ->map(static fn (object $identity): array => (array) $identity)
                ->all(),
            'passkeys' => $core->table('passkeys')
                ->where('user_id', $userId)
                ->orderBy('created_at')
                ->get(['name', 'last_used_at', 'created_at'])
                ->map(static fn (object $passkey): array => (array) $passkey)
                ->all(),
            'sessions' => $core->table('platform_auth_sessions')
                ->where('user_id', $userId)
                ->orderBy('created_at')
                ->get(['remembered', 'ip_address', 'user_agent', 'last_seen_at', 'revoked_at', 'created_at'])
                ->map(static fn (object $session): array => (array) $session)
                ->all(),
            'workspace_memberships' => $core->table('workspace_memberships as memberships')
                ->join('workspaces', 'workspaces.id', '=', 'memberships.workspace_id')
                ->where('memberships.user_id', $userId)
                ->orderBy('workspaces.name')
                ->get([
                    'workspaces.id as workspace_id',
                    'workspaces.name as workspace_name',
                    'workspaces.slug as workspace_slug',
                    'workspaces.status as workspace_status',
                    'memberships.role',
                    'memberships.status',
                    'memberships.invited_at',
                    'memberships.joined_at',
                    'memberships.expires_at',
                    'memberships.revoked_at',
                    'memberships.created_at',
                ])
                ->map(static fn (object $membership): array => (array) $membership)
                ->all(),
            'product_access' => $core->table('workspace_product_access as access')
                ->join('workspace_memberships as memberships', 'memberships.id', '=', 'access.membership_id')
                ->join('workspaces', 'workspaces.id', '=', 'memberships.workspace_id')
                ->where('memberships.user_id', $userId)
                ->orderBy('workspaces.name')
                ->orderBy('access.product')
                ->get([
                    'workspaces.id as workspace_id',
                    'workspaces.name as workspace_name',
                    'access.product',
                    'access.role',
                    'access.status',
                    'access.granted_at',
                    'access.expires_at',
                    'access.revoked_at',
                ])
                ->map(static fn (object $access): array => (array) $access)
                ->all(),
            'project_memberships' => $core->table('project_memberships as memberships')
                ->join('projects', 'projects.id', '=', 'memberships.project_id')
                ->join('workspaces', 'workspaces.id', '=', 'projects.workspace_id')
                ->where('memberships.user_id', $userId)
                ->orderBy('workspaces.name')
                ->orderBy('projects.name')
                ->get([
                    'workspaces.id as workspace_id',
                    'workspaces.name as workspace_name',
                    'projects.id as project_id',
                    'projects.name as project_name',
                    'projects.slug as project_slug',
                    'memberships.role',
                    'memberships.status',
                    'memberships.granted_at',
                    'memberships.revoked_at',
                ])
                ->map(static fn (object $membership): array => (array) $membership)
                ->all(),
            'owned_workspace_subscriptions' => $core->table('product_subscriptions as subscriptions')
                ->join('workspaces', 'workspaces.id', '=', 'subscriptions.workspace_id')
                ->where('workspaces.owner_user_id', $userId)
                ->orderBy('workspaces.name')
                ->orderBy('subscriptions.product')
                ->get([
                    'workspaces.id as workspace_id',
                    'workspaces.name as workspace_name',
                    'subscriptions.product',
                    'subscriptions.plan_key',
                    'subscriptions.status',
                    'subscriptions.quantity',
                    'subscriptions.trial_ends_at',
                    'subscriptions.current_period_starts_at',
                    'subscriptions.current_period_ends_at',
                    'subscriptions.cancel_at',
                    'subscriptions.canceled_at',
                ])
                ->map(static fn (object $subscription): array => (array) $subscription)
                ->all(),
            'private_dashboard_views' => $core->table('workspace_dashboard_views')
                ->where('owner_user_id', $userId)
                ->orderBy('created_at')
                ->get(['workspace_id', 'name', 'filters', 'created_at', 'updated_at'])
                ->map(static fn (object $view): array => [
                    'workspace_id' => $view->workspace_id,
                    'name' => $view->name,
                    'filters' => json_decode((string) $view->filters, true) ?: [],
                    'created_at' => $view->created_at,
                    'updated_at' => $view->updated_at,
                ])
                ->all(),
            'dashboard_selections' => $core->table('workspace_dashboard_selections')
                ->where('user_id', $userId)
                ->orderBy('workspace_id')
                ->get(['workspace_id', 'view_id', 'created_at', 'updated_at'])
                ->map(static fn (object $selection): array => (array) $selection)
                ->all(),
            'private_project_pins' => $core->table('workspace_project_pins')
                ->where('owner_user_id', $userId)
                ->orderBy('created_at')
                ->get(['workspace_id', 'project_id', 'scope_key', 'created_at'])
                ->map(static fn (object $pin): array => (array) $pin)
                ->all(),
            'notification_preferences' => $core->table('workspace_notification_preferences')
                ->where('user_id', $userId)
                ->orderBy('workspace_id')
                ->orderBy('scope_key')
                ->get(['workspace_id', 'project_id', 'product', 'severity', 'scope_key', 'enabled', 'created_at', 'updated_at'])
                ->map(static fn (object $preference): array => (array) $preference)
                ->all(),
            'notification_reads' => $core->table('workspace_notification_reads')
                ->where('user_id', $userId)
                ->orderBy('read_at')
                ->get(['workspace_id', 'notification_key', 'read_at'])
                ->map(static fn (object $read): array => (array) $read)
                ->all(),
            'submitted_feedback' => WorkspaceFeedback::query()
                ->where('user_id', $userId)
                ->orderBy('created_at')
                ->get(['workspace_id', 'product', 'category', 'severity', 'status', 'title', 'description', 'reproduction_steps', 'review_response', 'page', 'resolved_at', 'created_at', 'updated_at'])
                ->map(static fn (WorkspaceFeedback $feedback): array => [
                    'workspace_id' => $feedback->workspace_id,
                    'product' => $feedback->product,
                    'category' => $feedback->category,
                    'severity' => $feedback->severity,
                    'status' => $feedback->status,
                    'title' => $feedback->title,
                    'description' => $feedback->description,
                    'reproduction_steps' => $feedback->reproduction_steps,
                    'review_response' => $feedback->review_response,
                    'page' => $feedback->page,
                    'resolved_at' => $feedback->resolved_at?->toIso8601String(),
                    'created_at' => $feedback->created_at?->toIso8601String(),
                    'updated_at' => $feedback->updated_at?->toIso8601String(),
                ])
                ->all(),
            'workspace_access_history' => $core->table('workspace_membership_events as events')
                ->join('workspaces', 'workspaces.id', '=', 'events.workspace_id')
                ->where(function ($query) use ($userId): void {
                    $query->where('events.actor_user_id', $userId)
                        ->orWhere('events.subject_user_id', $userId);
                })
                ->orderBy('events.created_at')
                ->get([
                    'workspaces.id as workspace_id',
                    'workspaces.name as workspace_name',
                    'events.event',
                    'events.previous_role',
                    'events.new_role',
                    'events.actor_user_id',
                    'events.subject_user_id',
                    'events.created_at',
                ])
                ->map(static fn (object $event): array => [
                    'workspace_id' => $event->workspace_id,
                    'workspace_name' => $event->workspace_name,
                    'event' => $event->event,
                    'previous_role' => $event->previous_role,
                    'new_role' => $event->new_role,
                    'performed_by_account' => $event->actor_user_id === $userId,
                    'affected_account' => $event->subject_user_id === $userId,
                    'created_at' => $event->created_at,
                ])
                ->all(),
            'excluded_data' => [
                'description' => 'Passwords, authenticator and recovery secrets, passkey credentials, session tokens, provider tokens, workspace settings, and product operational records are not included.',
            ],
        ];
    }
}
