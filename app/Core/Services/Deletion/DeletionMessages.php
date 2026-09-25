<?php

namespace App\Core\Services\Deletion;

final class DeletionMessages
{
    public static function reason(?string $code): string
    {
        return match ($code) {
            'workspace_owner_required', 'owner_required', 'owner_changed', 'owner_membership_changed', 'deployer_workspace_not_owned_by_actor', 'deployer_workspace_owner_membership_missing' => __('Only the current workspace owner with an active owner membership can request deletion.'),
            'identity_projection_in_progress' => __('An app account or workspace is still being connected. Open that app again to finish setup, then review deletion again. Interrupted setup may need support to reconcile it.'),
            'remove_workspace_teammates', 'workspace_has_members', 'workspace_members_remain', 'workspace_has_other_members', 'workspace_has_teammates', 'deployer_workspace_has_other_members' => __('Remove the other workspace members before deleting it.'),
            'leave_shared_workspaces', 'foreign_workspace_membership', 'account_has_foreign_memberships', 'deployer_account_has_shared_workspace_membership' => __('Leave workspaces owned by other people before deleting your account.'),
            'deployer_account_has_foreign_owned_records', 'foreign_report_exports' => __('Some records in another workspace still depend on this account. Ask that workspace owner or support to reconcile them before continuing.'),
            'deployer_actor_has_unassigned_records' => __('Some legacy resources have no workspace assignment. Assign or reconcile them in Deployer before continuing.'),
            'settle_product_billing', 'pending_billing_reconciliation', 'active_subscription', 'billing_not_settled', 'billing_unsettled', 'deployer_active_billing' => __('Cancel paid plans, wait for their paid periods to end, and resolve any pending billing changes before continuing.'),
            'workspace_mapping_unresolved', 'account_mapping_unresolved', 'workspace_scope_incomplete', 'deletion_identity_changed' => __('An app account or workspace mapping needs to be reconciled before cleanup can continue.'),
            'deletion_preview_changed', 'deletion_workspace_changed', 'deletion_actor_changed' => __('The account or workspace changed. Review its current state before continuing.'),
            'account_not_active', 'account_session_changed' => __('Sign in to an active account again before requesting deletion.'),
            'fresh_sign_in_required' => __('Sign out and sign in again with your usual sign-in method, then confirm deletion within ten minutes.'),
            'deletion_confirmation_invalid' => __('The confirmation did not match. Review the request and enter the confirmation again.'),
            'cleanup_temporarily_unavailable', 'product_cleanup_unavailable', 'deployer_activity_claim_store_unavailable' => __('An app cleanup service is temporarily unavailable. The request remains saved and can be retried.'),
            'cleanup_lease_expired' => __('The previous cleanup worker stopped responding. The saved request can be retried.'),
            'workspace_cleanup_incomplete', 'product_cleanup_incomplete' => __('Waiting for the remaining app workspace cleanup to finish.'),
            'workspace_lifecycle_in_progress' => __('This workspace already has an operation in progress.'),
            'activity_draining', 'in_flight_delivery', 'deployer_operations_draining', 'telemetry_claim_in_flight', 'delivery_claim_in_flight', 'notification_claim_in_flight', 'outbox_claim_in_flight', 'probe_claim_in_flight' => __('Waiting for running app operations to finish. New activity is stopped.'),
            'deployer_active_operations' => __('Finish or stop active deployments, provisioning, commands, scheduled tasks, and backup operations before requesting deletion.'),
            'deployer_activity_claims_open' => __('Waiting for Deployer activity to finish. If an operation was interrupted, support must confirm that its worker and remote effects have stopped before cleanup can continue.'),
            'telemetry_claim_unresolved', 'delivery_claim_unresolved', 'notification_claim_unresolved', 'outbox_claim_unresolved', 'probe_claim_unresolved' => __('An interrupted app operation has an uncertain outcome. Support must reconcile it before cleanup can continue.'),
            'export_file_cleanup_pending' => __('Waiting for export files to be removed from storage.'),
            'core_activity_draining' => __('Waiting for previously claimed cross-app work to finish or be reconciled by its recovery worker.'),
            default => __('This app needs attention before cleanup can continue. Your saved request and completed steps are preserved.'),
        };
    }
}
