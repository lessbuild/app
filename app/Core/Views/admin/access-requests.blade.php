<x-signal.layouts.core-admin
    :title="__('Deployer access requests')"
    :description="__('Review private-beta demand without exposing applicant details outside platform administration.')"
>
    <x-signal.ui.page-header
        icon="user-add"
        :title="__('Deployer access requests')"
        :description="__('Review and export Deployer access requests from Buildpusher Core.')"
    />

    <x-scenes.admin.access-request-management
        :requests="$requests"
        :status="$status"
        :counts="$counts"
        :editing-request="$editingRequest"
        :review-dialog-id="$reviewDialogId"
        :review-dialog-open="$reviewDialogOpen"
        :route-names="$routeNames"
    />
</x-signal.layouts.core-admin>
