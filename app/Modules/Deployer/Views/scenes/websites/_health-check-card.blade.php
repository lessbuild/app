<article data-website-health-check class="p-4 sm:p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="ui-eyebrow text-[0.65rem]">{{ str($check->source)->title() }}</p>
            <p class="mt-1 text-sm font-bold text-ink">{{ $check->checked_at->diffForHumans() }}</p>
        </div>
        <x-signal.ui.badge :tone="$check->successful ? 'success' : 'danger'">{{ $check->successful ? __('Healthy') : __('Failed') }}</x-signal.ui.badge>
    </div>

    @if ($check->error)
        <p class="mt-3 whitespace-pre-wrap break-words border-l-2 border-danger pl-3 text-xs text-muted">{{ $check->error }}</p>
    @endif

    <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-3">
        <div>
            <dt class="ui-eyebrow text-[0.65rem]">{{ __('Response') }}</dt>
            <dd class="mt-1 text-ink">
                {{ $check->http_status ? __('HTTP :status', ['status' => $check->http_status]) : __('No status') }}
                <span class="mt-1 block text-xs text-muted">{{ $check->duration_ms !== null ? __(':duration ms', ['duration' => $check->duration_ms]) : __('Duration unavailable') }}</span>
            </dd>
        </div>
        <div class="sm:col-span-2">
            <dt class="ui-eyebrow text-[0.65rem]">{{ __('Endpoint') }}</dt>
            <dd class="mt-1 break-all font-mono text-xs text-ink">{{ $check->endpoint }}</dd>
        </div>
    </dl>
</article>
