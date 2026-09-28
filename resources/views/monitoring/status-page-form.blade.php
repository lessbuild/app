@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="$page ? __('Edit :page', ['page' => $page->name]) : __('Add a status page')" :description="__('Only choose monitors you’re happy to show publicly. Addresses, keys and settings never appear on the page.')">
    <form method="POST" action="{{ $page ? route('monitoring.status-pages.update', [$project, $page->id]) : route('monitoring.status-pages.store', $project) }}" class="grid gap-6">
        @csrf
        @if ($page) @method('PUT') @endif
        <x-signal.ui.card class="grid gap-6 p-4 sm:p-6">
            @include('monitoring._status-page-fields')
        </x-signal.ui.card>

        <div class="flex flex-wrap gap-3">
            <x-signal.ui.button type="submit" variant="primary">{{ $page ? __('Save status page') : __('Add status page') }}</x-signal.ui.button>
            <x-signal.ui.button :href="$page ? route('monitoring.status-pages.show', [$project, $page->id]) : route('monitoring.status-pages', $project)" variant="quiet">{{ __('Cancel') }}</x-signal.ui.button>
        </div>
    </form>

    @if ($page)
        <x-signal.ui.settings-section :title="__('Delete this page')" :description="__('The public address stops working at once, and its updates and subscribers are deleted. Monitors aren’t affected.')">
            <div class="p-4 sm:p-6">
                <x-signal.ui.button variant="danger" data-modal-trigger="delete-status-page">{{ __('Delete page') }}</x-signal.ui.button>
                <x-signal.overlays.delete-confirmation id="delete-status-page" :route="route('monitoring.status-pages.destroy', [$project, $page->id])" :title="__('Delete :page?', ['page' => $page->name])" :description="__('The public address stops working at once.')" :submit-label="__('Delete page')" />
            </div>
        </x-signal.ui.settings-section>
    @endif
</x-signal.layouts.project>
