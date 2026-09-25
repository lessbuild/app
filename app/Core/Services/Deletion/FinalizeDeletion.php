<?php

namespace App\Core\Services\Deletion;

use App\Core\Exceptions\Deletion\DeletionBlocked;
use App\Core\Models\DeletionRequest;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectConnection;
use App\Core\Models\Workspace;
use Illuminate\Support\Facades\DB;

/** Final Core scrub, in the caller's transaction, after every native receipt is complete. */
final class FinalizeDeletion
{
    public function __construct(private readonly DeletionAuthority $authority) {}

    public function handle(DeletionRequest $request): void
    {
        if (DB::connection('core')->transactionLevel() < 1 || $request->phase !== 'purge'
            || $request->steps()->where('status', '!=', 'completed')->exists()) {
            throw new DeletionBlocked('product_cleanup_incomplete');
        }
        $this->authority->assertRequest($request, lock: true);
        $db = DB::connection('core');
        $workspaceIds = $request->workspace_ids;
        $projects = Project::query()->whereIn('workspace_id', $workspaceIds)->pluck('id');
        $connections = ProjectConnection::query()->whereIn('project_id', $projects)->pluck('id');
        // Restricting foreign keys retain canonical identifiers. Personal content does not remain in those tombstones.
        $db->table('project_connection_deliveries')->whereIn('project_connection_id', $connections)->delete();
        $db->table('project_connection_events')->whereIn('project_connection_id', $connections)->delete();
        $db->table('project_lifecycle_events')->whereIn('project_id', $projects)->delete();
        $db->table('resource_restoration_requests')->whereIn('workspace_id', $workspaceIds)->delete();
        $db->table('project_blueprint_runs')->whereIn('workspace_id', $workspaceIds)->delete();
        $db->table('project_blueprints')->whereIn('workspace_id', $workspaceIds)->delete();
        $db->table('workspace_credential_mutations')->whereIn('workspace_id', $workspaceIds)->delete();
        $db->table('project_connections')->whereIn('id', $connections)->update([
            'capabilities' => '[]', 'metadata' => null, 'status' => 'disconnected', 'last_error_code' => null, 'updated_at' => now(),
        ]);
        $db->table('project_resources')->whereIn('project_id', $projects)->update([
            'name' => null, 'resource_public_id' => null, 'metadata' => null, 'status' => 'deleted', 'updated_at' => now(),
        ]);
        $db->table('project_products')->whereIn('project_id', $projects)->update(['status' => 'deleted', 'metadata' => null, 'updated_at' => now()]);
        $db->table('project_environments')->whereIn('project_id', $projects)->update([
            'name' => 'Deleted environment', 'metadata' => null, 'status' => 'deleted', 'updated_at' => now(),
        ]);
        foreach ($db->table('project_environments')->whereIn('project_id', $projects)->pluck('id') as $environmentId) {
            $db->table('project_environments')->where('id', $environmentId)->update(['slug' => 'deleted-'.strtolower($environmentId)]);
        }
        foreach (Project::query()->whereIn('id', $projects)->get() as $project) {
            $project->forceFill(['name' => 'Deleted project', 'slug' => 'deleted-'.strtolower($project->getKey()),
                'description' => null, 'metadata' => null, 'status' => 'deleted'])->save();
        }
        $members = $db->table('workspace_memberships')->whereIn('workspace_id', $workspaceIds)->pluck('id');
        $db->table('workspace_product_access')->whereIn('membership_id', $members)->update(['metadata' => null]);
        foreach (['workspace_invitations', 'workspace_membership_events', 'workspace_feedback',
            'workspace_dashboard_selections', 'workspace_dashboard_views', 'workspace_project_pins',
            'workspace_notification_reads', 'workspace_notification_preferences', 'workspace_notification_saved_filters',
            'workspace_feature_rollout_metrics', 'workspace_feature_rollout_changes', 'workspace_feature_rollouts',
            'current_product_subscriptions'] as $table) {
            $db->table($table)->whereIn('workspace_id', $workspaceIds)->delete();
        }
        // These records contain only the minimum settled provider identifiers and financial state.
        foreach (['billing_customers', 'product_subscriptions', 'product_billing_events'] as $table) {
            $db->table($table)->whereIn('workspace_id', $workspaceIds)->update(['metadata' => null, 'updated_at' => now()]);
        }
        $db->table('billing_customers')->whereIn('workspace_id', $workspaceIds)->update(['status' => 'deleted']);
        $db->table('product_subscriptions')->whereIn('workspace_id', $workspaceIds)->whereNull('provider_subscription_id')
            ->update(['status' => 'expired', 'canceled_at' => now()]);
        foreach (Workspace::query()->whereIn('id', $workspaceIds)->get() as $workspace) {
            $workspace->forceFill(['name' => 'Deleted workspace', 'slug' => 'deleted-'.strtolower($workspace->getKey()),
                'status' => 'deleted', 'settings' => ['deletion_request_id' => (string) $request->getKey()]])->save();
        }
        $mapIds = collect($request->identity_bindings)->filter(fn (array $binding): bool => $binding['canonical_entity'] !== 'user' || $request->kind === 'account')->pluck('id');
        LegacyIdentityMap::query()->whereIn('id', $mapIds)->update([
            'status' => 'deleted', 'metadata' => null, 'reconciliation_notes' => null, 'batch_key' => null, 'updated_at' => now(),
        ]);
        if ($request->kind === 'account') {
            $this->scrubAccount(PlatformUser::query()->findOrFail($request->actor_id));
        }
        $retained = array_values(array_unique(array_merge($request->retained ?? [],
            $request->steps()->pluck('retained')->flatten()->filter(fn ($value): bool => is_string($value))->all())));
        $request->forceFill(['status' => 'completed', 'completed_at' => now(), 'last_error_code' => null, 'retained' => $retained])->save();
    }

    private function scrubAccount(PlatformUser $user): void
    {
        $db = DB::connection('core');
        $db->table('project_blueprint_runs')->where('requested_by_user_id', $user->getKey())->delete();
        $db->table('workspace_credential_mutations')->where('actor_id', $user->getKey())->delete();
        foreach (['platform_sso_tickets', 'platform_auth_sessions', 'passkeys', 'user_identities',
            'workspace_notification_reads', 'workspace_notification_preferences', 'workspace_notification_saved_filters',
            'workspace_dashboard_selections', 'workspace_feedback', 'sessions'] as $table) {
            $db->table($table)->where('user_id', $user->getKey())->delete();
        }
        foreach (['workspace_dashboard_views', 'workspace_project_pins'] as $table) {
            $db->table($table)->where('owner_user_id', $user->getKey())->delete();
        }
        if (filled($user->email)) {
            $db->table('password_reset_tokens')->where('email', $user->email)->delete();
            $db->table('workspace_invitations')->where('email_normalized', $user->email_normalized)->delete();
        }
        $user->forceFill(['status' => 'deleted', 'name' => 'Deleted account', 'email' => null, 'email_normalized' => null,
            'email_verified_at' => null, 'password' => null, 'password_set_at' => null, 'auth_type' => null,
            'two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null,
            'remember_token' => null, 'preferences' => null])->save();
    }
}
