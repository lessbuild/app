@props([
    'overview',
    'title',
    'description' => null,
])

@php($project = $overview->project)

{{-- Project pages. The sidebar (ShellComposer) lists the project's sections; an optional actions slot fills the header's buttons. --}}
<x-signal.layouts.app :title="$title === $project->name ? $title : $title.' · '.$project->name" :description="$description">
    <x-signal.ui.page-header
        :eyebrow="$project->account->name"
        :title="$title"
        :description="$description"
        :breadcrumbs="app(\App\Http\View\ProjectBreadcrumbs::class)->handle($project)"
        class="mb-0 sm:mb-0"
    >
        @isset($actions)
            <x-slot:actions>{{ $actions }}</x-slot:actions>
        @endisset
    </x-signal.ui.page-header>

    @if ($project->is_sample)
        <x-signal.ui.alert tone="info">{{ __('This is a sample project: its visits, requests and errors are made up. Nothing here reaches the outside world.') }} <a href="{{ route('projects.settings', $project) }}" class="font-semibold underline">{{ __('Delete it') }}</a> {{ __('when you’re done, or create your own project.') }}</x-signal.ui.alert>
    @endif
    @if (session('status'))
        <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif
    @if (session('notice'))
        <x-signal.ui.alert tone="info" role="status">{{ session('notice') }}</x-signal.ui.alert>
    @endif

    {{ $slot }}
</x-signal.layouts.app>
