<x-signal.layouts.platform
    :title="__('Monitor status pages')"
    :description="__('Manage Monitor customer status pages from the shared workspace.')"
    :navigation="[]"
    :account-user="$user"
    :current-workspace="$workspace"
    :workspaces="$workspaces"
>
    <x-signal.ui.page-header
        eyebrow="{{ $workspace->name }} · {{ __('Monitor') }}"
        :title="__('Monitor status pages')"
        :description="__('Choose public components from the Monitor workspace. Monitor keeps the selected monitors, component history, and status-page records in its own database.')"
    >
        <x-slot:actions>
            <x-signal.ui.button :href="route('core.workspace.admin', $workspace)" variant="secondary">{{ __('Workspace management') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('core.workspace.dashboard', $workspace)" variant="quiet">{{ __('Overview') }}</x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    @if (! $management)
        <x-signal.ui.card class="mt-7 p-6">
            <x-signal.ui.empty-state
                :title="__('Monitor status pages are unavailable')"
                :description="__('Core could not verify one active Monitor workspace and account mapping for this Buildpusher workspace. Reconcile those mappings before changing product records.')"
                icon="globe"
            />
        </x-signal.ui.card>
    @else
        @if (session('success'))
            <x-signal.ui.alert class="mt-6" tone="success" role="status">{{ session('success') }}</x-signal.ui.alert>
        @endif
        @if ($errors->any())
            <x-signal.ui.alert class="mt-6" tone="danger" role="alert">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach
                </ul>
            </x-signal.ui.alert>
        @endif

        <section class="mt-7" aria-labelledby="monitor-status-pages-heading">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="ui-eyebrow">{{ __('Customer communication') }}</p>
                    <h2 id="monitor-status-pages-heading" class="mt-1 text-xl font-extrabold text-ink">{{ __('Published Monitor pages') }}</h2>
                    <p class="mt-1 text-sm text-muted">{{ __('Public pages show component labels, high-level health, and active Monitor incidents. Target URLs and credentials stay private.') }}</p>
                </div>
                <x-signal.ui.badge>{{ trans_choice(':count page|:count pages', count($management->pages), ['count' => count($management->pages)]) }}</x-signal.ui.badge>
            </div>

            @if ($management->pages === [])
                <x-signal.ui.card class="p-5">
                    <x-signal.ui.empty-state :title="__('No Monitor status pages yet')" :description="__('Select monitors that customers can safely see, then publish one stable service-health URL.')" icon="globe" />
                </x-signal.ui.card>
            @endif

            <div class="grid gap-4">
                @foreach ($management->pages as $page)
                    <x-signal.ui.card as="article" class="p-5 sm:p-6" aria-labelledby="monitor-status-page-{{ $page['id'] }}-heading">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 id="monitor-status-page-{{ $page['id'] }}-heading" class="text-lg font-extrabold text-ink">{{ $page['name'] }}</h3>
                                <p class="mt-1 text-sm text-muted">/{{ $page['slug'] }} · {{ trans_choice(':count component|:count components', count($page['component_names']), ['count' => count($page['component_names'])]) }}</p>
                                @if ($page['description'])<p class="mt-2 max-w-3xl text-sm leading-6 text-muted">{{ $page['description'] }}</p>@endif
                            </div>
                            <x-signal.ui.badge :tone="$page['published'] ? 'success' : 'neutral'">{{ $page['published'] ? __('Published') : __('Draft') }}</x-signal.ui.badge>
                        </div>

                        <div class="mt-4 flex flex-wrap items-center gap-3">
                            @if ($page['published'])
                                <x-signal.ui.button :href="$page['public_url']" variant="secondary" target="_blank" rel="noopener noreferrer">{{ __('Open public page') }}</x-signal.ui.button>
                            @else
                                <span class="text-xs text-muted">{{ __('This page remains private until published.') }}</span>
                            @endif
                            <span class="text-xs text-muted">{{ __('Components: :names', ['names' => $page['component_names'] === [] ? __('None selected') : implode(', ', $page['component_names'])]) }}</span>
                        </div>

                        @if ($management->canManage)
                            <details class="mt-5 border-t border-line pt-4">
                                <summary class="cursor-pointer text-sm font-bold text-primary focus-visible:outline-2 focus-visible:outline-focus">{{ __('Edit status page') }}</summary>
                                <form method="POST" action="{{ route('core.workspace.monitor-status-pages.update', [$workspace, $page['id']]) }}" class="mt-4 grid gap-4 md:grid-cols-2">
                                    @csrf
                                    @method('PATCH')
                                    <label class="block">
                                        <span class="ui-label">{{ __('Name') }}</span>
                                        <x-signal.ui.input name="name" value="{{ $page['name'] }}" maxlength="120" required class="ui-input" :restore="false" />
                                    </label>
                                    <label class="block">
                                        <span class="ui-label">{{ __('Public URL slug') }}</span>
                                        <x-signal.ui.input name="slug" value="{{ $page['slug'] }}" maxlength="100" class="ui-input" :restore="false" />
                                    </label>
                                    <label class="block md:col-span-2">
                                        <span class="ui-label">{{ __('Customer-facing description') }}</span>
                                        <x-signal.ui.textarea name="description" maxlength="1000" class="ui-input">{{ $page['description'] }}</x-signal.ui.textarea>
                                    </label>
                                    <fieldset class="grid gap-2 md:col-span-2 sm:grid-cols-2">
                                        <legend class="ui-label">{{ __('Public components') }}</legend>
                                        <p class="ui-help md:col-span-2">{{ __('Only the selected monitor name, type, and high-level health are shared. Private targets and credentials are excluded.') }}</p>
                                        @forelse ($management->monitors as $monitor)
                                            <label class="ui-choice items-start">
                                                <x-signal.ui.input type="checkbox" name="monitor_ids[]" value="{{ $monitor['id'] }}" class="ui-check mt-0.5" @checked(in_array($monitor['id'], $page['monitor_ids'], true)) :restore="false" />
                                                <span class="min-w-0 flex-1">
                                                    <span class="block text-sm font-semibold text-ink">{{ $monitor['name'] }}</span>
                                                    <span class="mt-1 block text-xs text-muted">{{ $monitor['type'] }} · {{ $monitor['application'] }} / {{ $monitor['environment'] }} · {{ $monitor['health'] }}</span>
                                                </span>
                                            </label>
                                        @empty
                                            <p class="text-sm text-muted">{{ __('Create a Monitor check before adding public components.') }}</p>
                                        @endforelse
                                    </fieldset>
                                    <label class="ui-choice md:col-span-2">
                                        <x-signal.ui.input type="hidden" name="published" value="0" :restore="false" />
                                        <x-signal.ui.input type="checkbox" name="published" value="1" class="ui-check" @checked($page['published']) :restore="false" />
                                        <span class="text-sm text-ink">{{ __('Publish this page') }}</span>
                                    </label>
                                    <div class="md:col-span-2"><x-signal.ui.button type="submit" variant="primary">{{ __('Save Monitor status page') }}</x-signal.ui.button></div>
                                </form>
                                <x-signal.ui.button
                                    :href="route('core.workspace.monitor-status-pages.index', $workspace).'#delete-monitor-status-page-'.$page['id']"
                                    data-modal-trigger="delete-monitor-status-page-{{ $page['id'] }}"
                                    aria-controls="delete-monitor-status-page-{{ $page['id'] }}"
                                    aria-expanded="false"
                                    variant="danger"
                                    class="mt-3"
                                >{{ __('Delete status page') }}</x-signal.ui.button>
                                <x-signal.overlays.delete-confirmation
                                    id="delete-monitor-status-page-{{ $page['id'] }}"
                                    :route="route('core.workspace.monitor-status-pages.destroy', [$workspace, $page['id']])"
                                    :title="__('Delete :page?', ['page' => $page['name']])"
                                    :description="__('The public URL will stop working. Monitor checks and their history stay unchanged.')"
                                />
                            </details>
                        @endif
                    </x-signal.ui.card>
                @endforeach
            </div>

            @if ($management->canManage)
                <x-signal.ui.card as="section" class="mt-5 p-5 sm:p-6" aria-labelledby="create-monitor-status-page-heading">
                    <h3 id="create-monitor-status-page-heading" class="text-lg font-extrabold text-ink">{{ __('Create a Monitor status page') }}</h3>
                    <form method="POST" action="{{ route('core.workspace.monitor-status-pages.store', $workspace) }}" class="mt-4 grid gap-4 md:grid-cols-2">
                        @csrf
                        <label class="block">
                            <span class="ui-label">{{ __('Page name') }}</span>
                            <x-signal.ui.input name="name" value="{{ old('name') }}" maxlength="120" required class="ui-input" placeholder="{{ __('Service status') }}" />
                        </label>
                        <label class="block">
                            <span class="ui-label">{{ __('Public URL slug') }}</span>
                            <x-signal.ui.input name="slug" value="{{ old('slug') }}" maxlength="100" class="ui-input" placeholder="service-status" />
                        </label>
                        <label class="block md:col-span-2">
                            <span class="ui-label">{{ __('Customer-facing description') }}</span>
                            <x-signal.ui.textarea name="description" maxlength="1000" class="ui-input">{{ old('description') }}</x-signal.ui.textarea>
                        </label>
                        <fieldset class="grid gap-2 md:col-span-2 sm:grid-cols-2">
                            <legend class="ui-label">{{ __('Public components') }}</legend>
                            <p class="ui-help md:col-span-2">{{ __('Only names, monitor types, and high-level health are shared; target URLs and credentials stay private.') }}</p>
                            @forelse ($management->monitors as $monitor)
                                <label class="ui-choice items-start">
                                    <x-signal.ui.input type="checkbox" name="monitor_ids[]" value="{{ $monitor['id'] }}" class="ui-check mt-0.5" :restore="false" />
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-semibold text-ink">{{ $monitor['name'] }}</span>
                                        <span class="mt-1 block text-xs text-muted">{{ $monitor['type'] }} · {{ $monitor['application'] }} / {{ $monitor['environment'] }} · {{ $monitor['health'] }}</span>
                                    </span>
                                </label>
                            @empty
                                <p class="text-sm text-muted">{{ __('Create a Monitor check before adding public components.') }}</p>
                            @endforelse
                        </fieldset>
                        <label class="ui-choice md:col-span-2">
                            <x-signal.ui.input type="hidden" name="published" value="0" :restore="false" />
                            <x-signal.ui.input type="checkbox" name="published" value="1" class="ui-check" :restore="false" />
                            <span class="text-sm text-ink">{{ __('Publish this page') }}</span>
                        </label>
                        <div class="md:col-span-2"><x-signal.ui.button type="submit" variant="primary">{{ __('Create Monitor status page') }}</x-signal.ui.button></div>
                    </form>
                </x-signal.ui.card>
            @endif
        </section>
    @endif
</x-signal.layouts.platform>
