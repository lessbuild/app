@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Create a server')" :description="__('The server provisions itself with the software its type needs. It takes about ten minutes.')">
    <x-signal.ui.plan-limit-alert service="infrastructure" />

    @include('infrastructure._server-create-form')
</x-signal.layouts.project>
