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
        :breadcrumbs="request()->routeIs('projects.show') ? [['label' => __('Projects'), 'href' => route('dashboard')]] : [['label' => __('Projects'), 'href' => route('dashboard')], ['label' => $project->name, 'href' => route('projects.show', $project)]]"
        class="mb-0 sm:mb-0"
    >
        @isset($actions)
            <x-slot:actions>{{ $actions }}</x-slot:actions>
        @endisset
    </x-signal.ui.page-header>

    @if (session('status'))
        <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif
    @if (session('notice'))
        <x-signal.ui.alert tone="info" role="status">{{ session('notice') }}</x-signal.ui.alert>
    @endif

    {{ $slot }}
</x-signal.layouts.app>
