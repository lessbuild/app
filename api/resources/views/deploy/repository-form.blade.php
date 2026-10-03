@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Connect a repository')" :description="__('It’s cloned over HTTPS with the provider’s token and deployed to the website you choose.')">
    {{-- The part a modal shows when this page is opened from a list (x-signal.overlays.page-modal). --}}
    <div data-modal-content class="grid gap-6">
        <x-signal.ui.card class="p-5 sm:p-6">
            <form method="POST" action="{{ route('deploy.repositories.store', $project) }}" class="grid items-start gap-5 sm:grid-cols-2">
                @csrf
                @include('deploy._repository-fields')
                <div class="sm:col-span-2"><x-signal.ui.button type="submit" variant="primary">{{ __('Connect repository') }}</x-signal.ui.button></div>
            </form>
        </x-signal.ui.card>
    </div>
</x-signal.layouts.project>
