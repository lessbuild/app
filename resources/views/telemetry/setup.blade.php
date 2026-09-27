@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Connect your app')" :description="__('Each environment has its own ingest keys. Send events as JSON or through OpenTelemetry.')">
    @if ($issuedKey)
        <x-signal.ui.alert tone="success" role="status">
            <p class="font-bold">{{ __('Copy this ingest key now. It won’t be shown again.') }}</p>
            <x-signal.ui.code-block :code="$issuedKey['secret']" class="mt-2 break-all whitespace-pre-wrap" />
        </x-signal.ui.alert>
    @endif

    <x-signal.ui.card class="overflow-hidden">
        <ul class="divide-y divide-line" aria-label="{{ __('Environments') }}">
            @foreach ($health['environments'] as $item)
                @php($environment = $item['environment'])
                <li class="grid gap-3 px-5 py-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-extrabold text-ink">{{ $environment->name }}</p>
                            <p class="mt-0.5 text-xs text-muted">{{ $item['description'] }} · {{ trans_choice(':count event received|:count events received', $environment->telemetry_event_count, ['count' => number_format($environment->telemetry_event_count)]) }}</p>
                        </div>
                        <span class="flex items-center gap-2">
                            <x-signal.ui.badge :tone="$item['state']->tone()">{{ $item['state']->label() }}</x-signal.ui.badge>
                            <x-signal.ui.button :href="route('monitoring.ingest.deliveries', [$project, $environment->id])" variant="quiet" size="sm">{{ __('Deliveries') }}</x-signal.ui.button>
                        </span>
                    </div>
                    @foreach ($tokens->get($environment->id, collect()) as $token)
                        <div class="flex flex-wrap items-center justify-between gap-2 rounded-control bg-surface-muted px-3 py-2 text-sm">
                            <span><span class="font-semibold text-ink">{{ $token->name }}</span> <code class="text-xs text-muted">{{ $token->prefix }}…</code>
                                <span class="text-xs text-muted">· {{ $token->last_used_at ? __('used :time', ['time' => $token->last_used_at->diffForHumans()]) : __('never used') }}@if ($token->expires_at) · {{ __('expires :date', ['date' => $token->expires_at->toFormattedDateString()]) }}@endif</span></span>
                            @if ($canManage)
                                <span class="flex gap-2">
                                    <form method="POST" action="{{ route('monitoring.keys.rotate', [$project, $token->id]) }}">
                                        @csrf
                                        <x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Replace') }}</x-signal.ui.button>
                                    </form>
                                    <form method="POST" action="{{ route('monitoring.keys.revoke', [$project, $token->id]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Revoke') }}</x-signal.ui.button>
                                    </form>
                                </span>
                            @endif
                        </div>
                    @endforeach
                    @if ($canManage)
                        <form method="POST" action="{{ route('monitoring.keys.store', [$project, $environment->id]) }}" class="flex flex-wrap items-end gap-2">
                            @csrf
                            <x-signal.ui.input-field name="name" :id="'key-name-'.$environment->id" :label="__('New key name')" value="{{ __('Collector') }}" maxlength="120" required :restore="false" />
                            <x-signal.ui.select-field name="expires_in_days" :id="'key-expiry-'.$environment->id" :label="__('Expires')">
                                <option value="">{{ __('Never') }}</option>
                                @foreach ([30, 90, 365] as $days)
                                    <option value="{{ $days }}">{{ trans_choice('In :count day|In :count days', $days, ['count' => $days]) }}</option>
                                @endforeach
                            </x-signal.ui.select-field>
                            <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Create key') }}</x-signal.ui.button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    </x-signal.ui.card>

    <x-signal.ui.settings-section :title="__('Send events')" :description="__('Pick your stack for a working example. Replace the key placeholder with an ingest key from above.')">
        <div class="grid gap-4 p-4 sm:p-6">
            <form method="GET" action="{{ route('monitoring.setup', $project) }}" class="flex flex-wrap items-end gap-2">
                <x-signal.ui.select-field name="stack" :label="__('Stack')">
                    @foreach ($stackOptions as $value => $label)
                        <option value="{{ $value }}" @selected($stack === $value)>{{ $label }}</option>
                    @endforeach
                </x-signal.ui.select-field>
                <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Show') }}</x-signal.ui.button>
            </form>
            <p class="text-sm text-muted">{{ $guide['install'] }}</p>
            <p class="text-sm text-muted">{{ $guide['token'] }}</p>
            <x-signal.ui.code-block :code="$guide['code']" class="overflow-x-auto text-xs" />
            <p class="text-xs text-muted">{{ $guide['verification'] }}</p>
        </div>
    </x-signal.ui.settings-section>

    <x-signal.ui.settings-section :title="__('OpenTelemetry')" :description="__('Any OpenTelemetry SDK or collector can export traces, logs and metrics over OTLP/HTTP with JSON.')">
        <div class="p-4 sm:p-6">
            <x-signal.ui.code-block :code="$otlp" class="overflow-x-auto text-xs" />
        </div>
    </x-signal.ui.settings-section>
</x-signal.layouts.project>
