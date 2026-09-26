<article data-provider-connection-check class="p-4 sm:p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="ui-eyebrow text-[0.65rem]">{{ str($check->provider_type)->headline() }}</p>
            <p class="mt-1 text-sm font-bold text-ink">{{ str($check->source)->title() }}</p>
        </div>
        <x-signal.ui.badge :tone="$check->successful ? 'success' : 'danger'">{{ $check->successful ? __('Healthy') : __('Failed') }}</x-signal.ui.badge>
    </div>

    @if ($check->error)
        <p class="mt-3 whitespace-pre-wrap break-words border-l-2 border-danger pl-3 text-xs text-muted">{{ $check->error }}</p>
    @endif

    <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
        <div>
            <dt class="ui-eyebrow text-[0.65rem]">{{ __('Response') }}</dt>
            <dd class="mt-1 text-ink">
                {{ $check->http_status ? __('HTTP :status', ['status' => $check->http_status]) : __('No status') }}
                <span class="mt-1 block text-xs text-muted">{{ __(':duration ms', ['duration' => $check->duration_ms]) }}</span>
            </dd>
        </div>
        <div class="sm:col-span-2">
            <dt class="ui-eyebrow text-[0.65rem]">{{ __('Endpoint') }}</dt>
            <dd class="mt-1 break-all font-mono text-xs text-ink">{{ $check->endpoint ?? __('Unavailable') }}</dd>
        </div>
        <div>
            <dt class="ui-eyebrow text-[0.65rem]">{{ __('Checked') }}</dt>
            <dd class="mt-1 text-ink" title="{{ $check->checked_at }}">{{ $check->checked_at->diffForHumans() }}</dd>
        </div>
    </dl>
</article>
