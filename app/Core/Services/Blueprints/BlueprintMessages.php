<?php

namespace App\Core\Services\Blueprints;

final class BlueprintMessages
{
    public static function reason(?string $reason): string
    {
        return match ($reason) {
            'authority_changed', 'authority_unavailable', 'native_access_changed' => __('Workspace, project, or app access changed. A current workspace manager with access to this project and app must review it.'),
            'environment_changed' => __('A selected environment changed or is no longer active. Restore its access before resuming.'),
            'resource_bindings_changed', 'native_binding_changed', 'native_identity_ambiguous', 'native_state_changed' => __('A resource or identity mapping changed. Reconcile it with the accepted preview before resuming.'),
            'timezone_conflict' => __('This Analytics site already has events. Its reporting timezone must remain unchanged.'),
            'worker_lease_lost' => __('The previous worker stopped responding. The saved operation can be resumed using its existing receipts.'),
            'intent_changed' => __('The saved operation no longer matches its accepted version. Support must reconcile it.'),
            'product_unavailable', 'source_unavailable' => __('This app is temporarily unavailable. The saved operation and completed steps are preserved.'),
            'plan_changed', 'capacity_exceeded' => __('The current app plan cannot accommodate these resources. Review its limits before resuming.'),
            'source_fenced' => __('This app workspace is being deleted. Blueprint changes are stopped.'),
            'resource_conflict' => __('A resource is already linked differently. Reconcile the existing resource before resuming.'),
            'invalid_product_result' => __('The app returned an incomplete provisioning receipt. Support must reconcile it before resources are linked.'),
            default => __('This app needs attention before provisioning can continue. Completed steps and the accepted version are preserved.'),
        };
    }
}
