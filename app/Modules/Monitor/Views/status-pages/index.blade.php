@extends('monitor::layouts.app')
@section('title', 'Status pages')
@section('breadcrumb', 'Status pages')
@section('content')
<div class="space-y-6">
    <x-monitor::ui.page-header eyebrow="Customer communication" title="Status pages" description="Share a calm, public view of the services your customers depend on.">
        @if($canManage)
            <x-slot:actions><x-monitor::ui.button :href="route('monitor.status-pages.create')"><x-monitor::icon name="plus" class="h-4 w-4" />Create status page</x-monitor::ui.button></x-slot:actions>
        @endif
    </x-monitor::ui.page-header>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse($statusPages as $statusPage)
            <x-signal.ui.card as="article" class="p-5">
                <div class="flex items-start justify-between gap-3"><div><h2 class="text-sm font-bold">{{ $statusPage->name }}</h2><p class="mt-1 text-xs text-muted dark:text-subtle">{{ $statusPage->components_count }} monitored components</p></div><x-monitor::ui.badge :tone="$statusPage->published ? 'green' : 'slate'">{{ $statusPage->published ? 'Published' : 'Draft' }}</x-monitor::ui.badge></div>
                @if($statusPage->description)<p class="mt-4 line-clamp-2 text-xs leading-5 text-muted dark:text-subtle">{{ $statusPage->description }}</p>@endif
                <div class="mt-5 flex flex-wrap items-center gap-3 border-t border-line pt-4 text-xs dark:border-line">
                    @if($statusPage->published)<a href="{{ route('core.status-pages.show', ['product' => 'monitor', 'slug' => $statusPage->slug]) }}" target="_blank" rel="noopener" class="font-bold text-primary hover:underline dark:text-primary">Open public page <x-monitor::icon name="external" class="inline h-3 w-3" /></a>@endif
                    @if($canManage)<a href="{{ route('monitor.status-pages.edit', $statusPage) }}" class="font-semibold text-muted hover:text-ink dark:text-subtle dark:hover:text-emphasis-ink">Edit</a>@endif
                </div>
            </x-signal.ui.card>
        @empty
            <x-monitor::ui.empty-state icon="globe" class="md:col-span-2 xl:col-span-3" title="Give customers a useful signal" description="Choose the monitors that should appear on a public page, then share one stable URL for service health and active incidents.">
                @if($canManage)
                    <x-slot:action>
                        <x-monitor::ui.button :href="route('monitor.status-pages.create')" variant="secondary" size="sm">Create your first status page →</x-monitor::ui.button>
                    </x-slot:action>
                @endif
            </x-monitor::ui.empty-state>
        @endforelse
    </div>
    {{ $statusPages->links() }}
</div>
@endsection
