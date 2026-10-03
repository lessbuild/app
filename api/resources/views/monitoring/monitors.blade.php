@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Monitors')" :description="__('Uptime, DNS, TLS and TCP checks, plus heartbeats from your jobs and signals from your queues.')">
    @if ($canManage)
        <div class="flex justify-end">
            <x-signal.ui.button :href="route('monitoring.monitors.create', $project)" data-modal-trigger="add-monitor" :data-modal-history-url="route('monitoring.monitors', [$project, 'dialog' => 'add-monitor'])" variant="primary">{{ __('Add a monitor') }}</x-signal.ui.button>
        </div>
    @endif

    @if ($monitors === [])
        <x-signal.ui.empty-state icon="check-circle" :title="__('Add your first monitor')" :description="__('Check a URL every few minutes, watch a certificate or DNS record, or have a cron job report in. When something breaks, an incident opens and your alert destinations hear about it.')" />
    @else
        <div id="monitors-live" data-live-region data-live-interval="15000">
            <x-signal.ui.card class="overflow-hidden">
                <ul class="divide-y divide-line" aria-label="{{ __('Monitors') }}">
                    @foreach ($monitors as $monitor)
                        @php($health = $monitor->healthLabel())
                        <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                            <div class="min-w-0">
                                <a href="{{ route('monitoring.monitors.show', [$project, $monitor->id]) }}" class="font-extrabold text-ink hover:underline">{{ $monitor->name }}</a>
                                <p class="mt-0.5 break-all text-xs text-muted">{{ $monitor->typeLabel() }} · {{ $monitor->environment->name }} · {{ $monitor->targetLabel() }}</p>
                            </div>
                            <div class="flex items-center gap-3 text-xs text-muted">
                                @if ($monitor->checked_at)
                                    <span>{{ __('Checked :time', ['time' => $monitor->checked_at->diffForHumans()]) }}</span>
                                @endif
                                <x-signal.ui.badge :tone="match ($health) { 'Up' => 'success', 'Down' => 'danger', default => 'neutral' }">{{ __($health) }}</x-signal.ui.badge>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-signal.ui.card>
        </div>
    @endif

    <x-signal.ui.settings-section id="third-party" :title="__('Services you depend on')" :description="__('The public status of the services your apps rely on, checked every five minutes, beside your own monitors.')">
        <div class="grid gap-4 p-4 sm:p-6">
            @forelse ($thirdParty as $service)
                <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <div class="min-w-0">
                        <a href="{{ $service->url }}" class="font-bold text-ink hover:underline" rel="noopener noreferrer" target="_blank">{{ $service->name }}</a>
                        <p class="text-xs text-muted">
                            {{ $service->incident ?? $service->description ?? '' }}@if ($service->affected) · {{ __('Affected: :components', ['components' => implode(', ', $service->affected)]) }}@endif
                            @if ($service->checked_at) · {{ __('checked :time', ['time' => $service->checked_at->diffForHumans()]) }}@endif
                        </p>
                        @if ($service->last_error)<p class="text-xs text-danger">{{ $service->last_error }}</p>@endif
                    </div>
                    <div class="flex items-center gap-2">
                        <x-signal.ui.badge :tone="$service->tone()">{{ $service->label() }}</x-signal.ui.badge>
                        @if ($canManage)
                            <form method="POST" action="{{ route('monitoring.third-party.destroy', [$project, $service->id]) }}">
                                @csrf @method('DELETE')
                                <x-signal.ui.button type="submit" variant="quiet" size="sm" :aria-label="__('Stop following :name', ['name' => $service->name])">{{ __('Remove') }}</x-signal.ui.button>
                            </form>
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-sm text-muted">{{ __('Not following any services yet.') }}</p>
            @endforelse
            @if ($canManage)
                <form method="POST" action="{{ route('monitoring.third-party.store', $project) }}" class="grid gap-3 sm:grid-cols-3 sm:items-end">
                    @csrf
                    <x-signal.ui.select-field name="provider" :label="__('Service')">
                        @foreach (\App\Support\Monitoring\StatusProviders::LIST as $key => $provider)
                            <option value="{{ $key }}">{{ $provider['name'] }}</option>
                        @endforeach
                        <option value="custom">{{ __('Another status page…') }}</option>
                    </x-signal.ui.select-field>
                    <x-signal.ui.input-field name="name" :label="__('Name (another status page)')" maxlength="80" />
                    <x-signal.ui.input-field name="url" type="url" :label="__('Status page address')" maxlength="255" placeholder="https://status.example.com" :description="__('Any status page built on Atlassian Statuspage.')" />
                    <div class="sm:col-span-3"><x-signal.ui.button type="submit" variant="secondary">{{ __('Follow') }}</x-signal.ui.button></div>
                </form>
            @endif
        </div>
    </x-signal.ui.settings-section>

    @if ($canManage)
        <x-signal.overlays.page-modal id="add-monitor" :title="__('Add a monitor')" :src="route('monitoring.monitors.create', $project)" size="wide" />
    @endif
</x-signal.layouts.project>
