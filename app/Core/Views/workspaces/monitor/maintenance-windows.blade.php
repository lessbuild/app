<x-signal.layouts.platform :title="__('Monitor maintenance windows')" :description="__('Schedule UTC windows that suppress alert notifications while ingestion continues.')" :navigation="[]" :account-user="$user" :current-workspace="$workspace" :workspaces="$workspaces" :context-projects="$contextProjects">
    <x-signal.ui.page-header :eyebrow="$workspace->name" :title="__('Maintenance windows')" :description="__('Schedule planned work in UTC. Alert notifications are suppressed during each window; Monitor ingestion continues.')">
        <x-slot:actions>
            <x-signal.ui.button :href="route('core.workspace.monitor.alerts', $workspace)" variant="secondary">{{ __('Alert rules') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('core.workspace.monitor.destinations', $workspace)" variant="secondary">{{ __('Alert destinations') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('core.workspace.monitor.configuration.index', $workspace)" variant="secondary">{{ __('Monitor configuration') }}</x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    @if (session('status')) <x-signal.ui.alert tone="success" class="mt-5">{{ session('status') }}</x-signal.ui.alert> @endif
    @if ($errors->any()) <x-signal.ui.alert tone="danger" class="mt-5">{{ $errors->first() }}</x-signal.ui.alert> @endif

    @if ($canManage)
        @php($restoreCreate = old('_method') === null && old('window_reference') === null)
        <x-signal.ui.card class="mt-6 p-5">
            <h2 class="text-lg font-extrabold text-ink">{{ __('Schedule maintenance') }}</h2>
            <p class="mt-2 text-sm leading-6 text-muted">{{ __('Use UTC. This suppresses alert notifications while the window is active; telemetry ingestion continues.') }}</p>
            <form method="POST" action="{{ route('core.workspace.monitor.maintenance-windows.store', $workspace) }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                @csrf
                <x-signal.ui.input-field id="new-maintenance-name" name="name" :label="__('Window name')" :value="$restoreCreate ? old('name') : null" maxlength="120" required :restore="false" />
                <x-signal.ui.textarea-field id="new-maintenance-reason" name="reason" :label="__('Reason (optional)')" :value="$restoreCreate ? old('reason') : null" maxlength="1000" rows="2" :restore="false" />
                <x-signal.ui.input-field id="new-maintenance-start" name="starts_at" :label="__('Starts (UTC)')" type="datetime-local" :value="$restoreCreate ? old('starts_at', now('UTC')->addHour()->startOfMinute()->format('Y-m-d\\TH:i')) : now('UTC')->addHour()->startOfMinute()->format('Y-m-d\\TH:i')" required :restore="false" />
                <x-signal.ui.input-field id="new-maintenance-end" name="ends_at" :label="__('Ends (UTC)')" type="datetime-local" :value="$restoreCreate ? old('ends_at', now('UTC')->addHours(2)->startOfMinute()->format('Y-m-d\\TH:i')) : now('UTC')->addHours(2)->startOfMinute()->format('Y-m-d\\TH:i')" required :restore="false" />
                <p class="text-xs text-muted sm:col-span-2">{{ __('Both times use UTC. The end must be later than the start.') }}</p>
                <x-signal.ui.button type="submit" variant="primary" class="sm:col-span-2">{{ __('Schedule window') }}</x-signal.ui.button>
            </form>
        </x-signal.ui.card>
    @endif

    <div class="mt-6 grid gap-4">
        @forelse ($items as $item)
            @php($windowIndex = $loop->index)
            @php($restoreEdit = old('_method') === 'PATCH' && old('form_key') === $item['form_key'])
            <x-signal.ui.card class="p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-extrabold text-ink">{{ $item['name'] }}</h2>
                        <p class="mt-1 text-sm text-muted">{{ __(':start through :end', ['start' => $item['starts_at_label'], 'end' => $item['ends_at_label']]) }}</p>
                    </div>
                    <span class="ui-eyebrow">{{ __('UTC') }}</span>
                </div>
                @if ($item['reason'] !== '')
                    <p class="mt-3 whitespace-pre-line text-sm leading-6 text-muted">{{ $item['reason'] }}</p>
                @endif

                @if ($item['can_mutate'])
                    <x-signal.ui.disclosure :title="__('Edit or remove this window')" class="mt-4">
                        <form method="POST" action="{{ route('core.workspace.monitor.maintenance-windows.update', $workspace) }}" class="mt-4 grid gap-3 sm:grid-cols-2">
                            @csrf @method('PATCH')
                            <x-signal.ui.input type="hidden" name="window_reference" :value="$item['reference']" :restore="false" />
                            <x-signal.ui.input type="hidden" name="form_key" :value="$item['form_key']" :restore="false" />
                            <x-signal.ui.input type="hidden" name="version" :value="$item['version']" :restore="false" />
                            <x-signal.ui.input-field :id="'maintenance-'.$windowIndex.'-name'" name="name" :label="__('Window name')" :value="$restoreEdit ? old('name', $item['name']) : $item['name']" maxlength="120" required :restore="false" />
                            <x-signal.ui.textarea-field :id="'maintenance-'.$windowIndex.'-reason'" name="reason" :label="__('Reason (optional)')" :value="$restoreEdit ? old('reason', $item['reason']) : $item['reason']" maxlength="1000" rows="2" :restore="false" />
                            <x-signal.ui.input-field :id="'maintenance-'.$windowIndex.'-start'" name="starts_at" :label="__('Starts (UTC)')" type="datetime-local" :value="$restoreEdit ? old('starts_at', $item['starts_at']) : $item['starts_at']" required :restore="false" />
                            <x-signal.ui.input-field :id="'maintenance-'.$windowIndex.'-end'" name="ends_at" :label="__('Ends (UTC)')" type="datetime-local" :value="$restoreEdit ? old('ends_at', $item['ends_at']) : $item['ends_at']" required :restore="false" />
                            <p class="text-xs text-muted sm:col-span-2">{{ __('Times use UTC. Saving requires this window version to remain current.') }}</p>
                            <x-signal.ui.button type="submit" variant="primary" class="sm:col-span-2">{{ __('Save window') }}</x-signal.ui.button>
                        </form>
                        <form method="POST" action="{{ route('core.workspace.monitor.maintenance-windows.destroy', $workspace) }}" class="mt-3">
                            @csrf @method('DELETE')
                            <x-signal.ui.input type="hidden" name="window_reference" :value="$item['reference']" :restore="false" />
                            <x-signal.ui.input type="hidden" name="version" :value="$item['version']" :restore="false" />
                            <x-signal.ui.checkbox :id="'maintenance-'.$windowIndex.'-confirm-remove'" name="confirm_remove" value="1" required :restore="false">{{ __('I confirm removing this window and ending its alert notification suppression.') }}</x-signal.ui.checkbox>
                            <x-signal.ui.button type="submit" variant="danger">{{ __('Remove window') }}</x-signal.ui.button>
                        </form>
                    </x-signal.ui.disclosure>
                @elseif ($canManage)
                    <p class="mt-4 text-sm text-muted">{{ __('This saved window version is unavailable, so it can be viewed but not changed. Reload the page and contact an administrator if the problem continues.') }}</p>
                @endif
            </x-signal.ui.card>
        @empty
            <x-signal.ui.empty-state :title="__('No maintenance windows')" :description="__('Scheduled maintenance appears here for this Monitor workspace.')" icon="clock" />
        @endforelse
    </div>

    @if ($items->hasPages())
        <nav class="mt-5" aria-label="{{ __('Maintenance window pages') }}">{{ $items->links() }}</nav>
    @endif
</x-signal.layouts.platform>
