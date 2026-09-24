<div class="flex h-full flex-col gap-7 p-5">
    <a class="flex items-center gap-3 px-1 text-base font-extrabold tracking-tight text-ink" href="{{ route('analytics.dashboard') }}"><span class="grid size-9 place-items-center rounded-xl bg-zinc-900 text-xs font-black text-white dark:bg-zinc-100 dark:text-zinc-900">BP</span><span>Buildpusher <span class="text-muted">Analytics</span></span></a>
    @php($workspace = $currentWorkspace ?? null)
    @php($role = $currentAnalyticsWorkspaceRole ?? null)
    <x-signal.ui.panel as="section" class="border-0 bg-surface-muted p-3 shadow-none">
        <p class="text-[0.68rem] font-extrabold uppercase tracking-wider text-subtle">Workspace</p>
        <p class="mt-1 truncate text-sm font-bold text-ink">{{ $workspace?->name ?? 'Your workspace' }}</p>
        <x-signal.ui.link class="mt-2 text-xs" :href="route('analytics.workspaces.index')" variant="muted" size="inline">Switch workspace</x-signal.ui.link>
    </x-signal.ui.panel>
    <nav class="flex-1 space-y-1" aria-label="Application navigation">
        <a class="sidebar-link" href="{{ route('analytics.dashboard') }}" @if(request()->routeIs('analytics.dashboard')) aria-current="page" @endif><span aria-hidden="true">▦</span> Overview</a>
        <a class="sidebar-link" href="{{ $workspace?->sites->first() ? ($role?->canManageSites() ? route('analytics.sites.setup', $workspace->sites->first()) : route('analytics.dashboard')) : route('analytics.sites.create') }}" @if(request()->routeIs('analytics.sites.*')) aria-current="page" @endif><span aria-hidden="true">◫</span> Websites</a>
        @if ($role?->canManageSites())
            <a class="sidebar-link" href="{{ route('analytics.sites.create') }}"><span aria-hidden="true">＋</span> Add website</a>
        @endif
        @if ($workspace?->sites->first())
            <a class="sidebar-link" href="{{ route('analytics.goals.index', $workspace->sites->first()) }}"><span aria-hidden="true">◇</span> Goals</a>
            <a class="sidebar-link" href="{{ route('analytics.workspaces.team', $workspace) }}"><span aria-hidden="true">♙</span> Team</a>
        @endif
    </nav>
    <div class="border-t border-line pt-4"><p class="truncate text-sm font-bold text-ink">{{ auth()->user()->name }}</p><p class="truncate text-xs text-muted">{{ auth()->user()->email }}</p><x-signal.ui.link class="mt-2 text-xs" :href="route('analytics.account.profile')" variant="muted" size="inline">Profile and security</x-signal.ui.link><p class="mt-3 text-[0.68rem] leading-5 text-subtle">Cookieless estimates · 90-day detail retention</p></div>
</div>
