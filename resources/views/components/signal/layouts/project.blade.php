@props([
    'overview',
    'title',
    'description' => null,
])

@php($project = $overview->project)
@php($sections = ['projects.show' => [__('Overview'), route('projects.show', $project)]])
@foreach ($overview->enabledServices() as $service)
    @if ($service->canUse)
        @php($sections['projects.services.show:'.$service->key] = [$service->name, $service->url])
    @endif
@endforeach
@if ($overview->canManage)
    @php($sections['projects.settings'] = [__('Settings'), route('projects.settings', $project)])
@endif

{{-- Project pages; Phase 2's shell slice moves this navigation into the project sidebar. --}}
<x-signal.layouts.app :title="$title.' · '.$project->name" :description="$description">
    <x-signal.ui.page-header
        :eyebrow="$project->account->name"
        :title="$title"
        :description="$description"
        :breadcrumbs="request()->routeIs('projects.show') ? [['label' => __('Projects'), 'href' => route('dashboard')]] : [['label' => __('Projects'), 'href' => route('dashboard')], ['label' => $project->name, 'href' => route('projects.show', $project)]]"
        class="mb-0 sm:mb-0"
    />

    <x-signal.ui.local-nav :label="__('Project sections')">
        @foreach ($sections as $key => [$label, $url])
            @php([$routeName, $serviceKey] = array_pad(explode(':', $key, 2), 2, null))
            @php($current = request()->routeIs($routeName) && ($serviceKey === null || request()->route('service') === $serviceKey))
            <a href="{{ $url }}" class="ui-local-nav__link" @if ($current) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </x-signal.ui.local-nav>

    @if (session('status'))
        <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif

    {{ $slot }}
</x-signal.layouts.app>
