<x-signal.layouts.platform
    :title="__('Analytics goals')"
    :description="__('Manage path and event conversion goals for an Analytics site.')"
    :navigation="$navigation"
    :account-user="$accountUser"
    :current-workspace="$currentWorkspace"
    :workspaces="$workspaces"
    :context-projects="$contextProjects"
>
    <x-signal.ui.page-header :eyebrow="$workspace->name" :title="$snapshot->siteName" :description="__('Conversion goals')">
        <x-slot:actions><x-signal.ui.button :href="route('core.workspace.analytics.sites.index', $workspace)" variant="secondary">{{ __('All sites') }}</x-signal.ui.button></x-slot:actions>
    </x-signal.ui.page-header>
    @if (! $snapshot->available)<x-signal.ui.alert class="mt-5" tone="warning" role="status">{{ __('Analytics goal data is temporarily unavailable.') }}</x-signal.ui.alert>@endif
    @if (session('status'))<x-signal.ui.alert class="mt-5" tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>@endif
    @if ($errors->any())<x-signal.ui.alert class="mt-5" tone="danger" role="alert"><ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-signal.ui.alert>@endif

    @if ($snapshot->canManage)
        <x-signal.ui.card as="form" method="POST" :action="route('core.workspace.analytics.goals.create', [$workspace, $snapshot->siteId])" class="mt-6 grid gap-4 p-5 sm:grid-cols-2">
            @csrf <h2 class="text-lg font-extrabold sm:col-span-2">{{ __('Add a goal') }}</h2>
            <x-signal.ui.input-field name="name" :label="__('Goal name')" required />
            <x-signal.ui.select-field name="kind" :label="__('Goal type')"><option value="path">{{ __('Path') }}</option><option value="event">{{ __('Event') }}</option></x-signal.ui.select-field>
            <x-signal.ui.select-field name="match_type" :label="__('Match type')"><option value="exact">{{ __('Exact') }}</option><option value="prefix">{{ __('Prefix') }}</option></x-signal.ui.select-field>
            <x-signal.ui.input-field name="match_value" :label="__('Path or event name')" required />
            <x-signal.ui.choice id="goal-active" name="active" :label="__('Goal active')" :checked="true" unchecked-value="0" card />
            <div class="flex items-end justify-end"><x-signal.ui.button type="submit" variant="primary">{{ __('Create goal') }}</x-signal.ui.button></div>
        </x-signal.ui.card>
    @endif

    <section class="mt-8 space-y-4" aria-label="{{ __('Existing goals') }}">
        @forelse ($snapshot->goals as $goal)
            <x-signal.ui.card class="grid gap-4 p-5">
                <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="font-extrabold">{{ $goal->name }}</h2><p class="mt-1 text-sm text-muted">{{ ucfirst($goal->kind) }} · {{ $goal->matchType }} · <code>{{ $goal->matchValue }}</code></p></div><x-signal.ui.badge :tone="$goal->active ? 'success' : 'neutral'">{{ $goal->active ? __('Active') : __('Paused') }}</x-signal.ui.badge></div>
                @if ($snapshot->canManage)
                    <div class="grid gap-3 border-t border-line pt-4">
                        <form method="POST" action="{{ route('core.workspace.analytics.goals.update', [$workspace, $snapshot->siteId, $goal->id]) }}" class="grid gap-3 sm:grid-cols-2">
                            @csrf @method('PUT')
                            <x-signal.ui.input-field name="name" :label="__('Goal name')" :value="$goal->name" required />
                            <x-signal.ui.select-field name="kind" :label="__('Goal type')"><option value="path" @selected($goal->kind === 'path')>{{ __('Path') }}</option><option value="event" @selected($goal->kind === 'event')>{{ __('Event') }}</option></x-signal.ui.select-field>
                            <x-signal.ui.select-field name="match_type" :label="__('Match type')"><option value="exact" @selected($goal->matchType === 'exact')>{{ __('Exact') }}</option><option value="prefix" @selected($goal->matchType === 'prefix')>{{ __('Prefix') }}</option></x-signal.ui.select-field>
                            <x-signal.ui.input-field name="match_value" :label="__('Path or event name')" :value="$goal->matchValue" required />
                            <x-signal.ui.choice id="active-{{ $goal->id }}" name="active" :label="__('Goal active')" :checked="$goal->active" unchecked-value="0" card />
                            <div class="flex items-end justify-end gap-2"><x-signal.ui.button type="submit" variant="secondary">{{ __('Save goal') }}</x-signal.ui.button></div>
                        </form>
                        <form method="POST" action="{{ route('core.workspace.analytics.goals.delete', [$workspace, $snapshot->siteId, $goal->id]) }}" class="flex justify-end">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="danger">{{ __('Remove goal') }}</x-signal.ui.button></form>
                    </div>
                @endif
            </x-signal.ui.card>
        @empty
            <x-signal.ui.empty-state :title="__('No goals yet')" :description="__('Create a path goal for a thank-you page or an event goal for a named action.')" icon="chart-bar" />
        @endforelse
    </section>
</x-signal.layouts.platform>
