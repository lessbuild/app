<x-signal.layouts.platform :title="__('Project blueprints')" :account-user="$user" :current-workspace="$workspace" :workspaces="$workspaces">
    <x-signal.ui.page-header :eyebrow="$workspace->name" :title="__('Project blueprints')" :description="__('Save a version of your app setup, preview its resources and plan limits, and apply it to a shared project.')">
        <x-slot:actions><x-signal.ui.button :href="route('core.workspace.admin', $workspace)" variant="secondary">{{ __('Workspace management') }}</x-signal.ui.button></x-slot:actions>
    </x-signal.ui.page-header>
    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <section class="space-y-3" aria-label="{{ __('Saved blueprints') }}">
            @forelse ($blueprints as $blueprint)
                <x-signal.ui.card class="p-5">
                    <h2 class="font-bold text-ink">{{ $blueprint->name }}</h2>
                    <p class="mt-2 text-sm text-muted">{{ $blueprint->description }}</p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach ($blueprint->versions as $version)
                            <x-signal.ui.button :href="route('core.workspace.blueprints.show', [$workspace, $version])" variant="secondary" size="sm">{{ __('Version :version', ['version' => $version->version]) }}</x-signal.ui.button>
                        @endforeach
                    </div>
                </x-signal.ui.card>
            @empty
                <x-signal.ui.empty-state :title="__('No blueprints yet')" :description="__('Create a definition to reuse a setup across your projects. Each change becomes a new version.')" />
            @endforelse
            {{ $blueprints->links() }}
        </section>
        <x-signal.ui.card as="form" method="POST" :action="route('core.workspace.blueprints.store', $workspace)" class="space-y-4 p-5">
            @csrf
            <h2 class="font-bold text-ink">{{ __('Create a blueprint') }}</h2>
            <x-signal.ui.input-field name="name" :label="__('Name')" required maxlength="120" />
            <x-signal.ui.textarea-field name="description" :label="__('Description')" maxlength="1000" rows="2" />
            <x-signal.ui.textarea-field name="definition" :label="__('Versioned definition')" :value="$example" :restore="false" rows="18" required class="font-mono text-xs" />
            <p class="text-xs leading-5 text-muted">{{ __('Use the app examples above as a starting point. Remove apps you do not need. Supply secrets separately in each app; blueprints cannot change subscriptions or mark domains and trackers as verified.') }}</p>
            @if (! $hasProviders)<x-signal.ui.alert tone="warning">{{ __('No available app currently provides blueprints for your workspace access.') }}</x-signal.ui.alert>@endif
            <x-signal.ui.button type="submit" :disabled="! $hasProviders">{{ __('Save version 1') }}</x-signal.ui.button>
        </x-signal.ui.card>
    </div>
    @if ($runs->isNotEmpty())
        <x-signal.ui.card class="mt-6 p-5">
            <h2 class="font-bold text-ink">{{ __('Recent applications') }}</h2>
            <ul class="mt-3 divide-y divide-line">
                @foreach ($runs as $run)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div><x-signal.ui.badge tone="neutral">{{ str($run->status)->headline() }}</x-signal.ui.badge><time class="ml-2 text-xs text-muted" datetime="{{ $run->created_at->toIso8601String() }}">{{ $run->created_at->format('Y-m-d H:i') }}</time></div>
                        <x-signal.ui.button :href="route('core.workspace.blueprints.runs.show', [$workspace, $run])" variant="secondary" size="sm">{{ __('View progress') }}</x-signal.ui.button>
                    </li>
                @endforeach
            </ul>
        </x-signal.ui.card>
    @endif
</x-signal.layouts.platform>
