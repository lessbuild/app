{{-- One finding: severity, what's wrong and how to fix it, with ignore/reopen for people who manage Security. --}}
<li class="grid gap-2 py-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="flex flex-wrap items-center gap-2">
                <x-signal.ui.badge :tone="$finding->tone()">{{ $finding->severityLabel() }}</x-signal.ui.badge>
                <span class="text-xs font-bold uppercase tracking-wide text-muted">{{ __(\App\Models\SecurityFinding::SOURCES[$finding->source] ?? $finding->source) }}</span>
                @if ($finding->subject)<span class="text-xs text-muted">· {{ $finding->subject }}</span>@endif
            </p>
            <p class="mt-1 font-bold text-ink">{{ $finding->title }}</p>
            @if ($finding->detail)<p class="mt-1 text-sm text-muted">{{ $finding->detail }}</p>@endif
            @if ($finding->fix)<p class="mt-1 text-sm"><span class="font-semibold text-ink">{{ __('Fix:') }}</span> <span class="text-muted">{{ $finding->fix }}</span></p>@endif
            @if ($finding->status === 'ignored' && $finding->ignored_reason)<p class="mt-1 text-xs text-muted">{{ __('Ignored: :reason', ['reason' => $finding->ignored_reason]) }}</p>@endif
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            <span class="text-xs text-muted">{{ $finding->status === 'resolved' ? __('resolved :time', ['time' => $finding->resolved_at?->diffForHumans()]) : __('seen :time', ['time' => $finding->last_seen_at->diffForHumans()]) }}</span>
            @if ($finding->url)<x-signal.ui.button :href="$finding->url" variant="quiet" size="sm" target="_blank" rel="noopener">{{ __('Details') }}</x-signal.ui.button>@endif
            @php($fixAction = $finding->data['fix_action'] ?? null)
            @if ($canManage && $finding->status === 'open' && is_string($fixAction) && isset(\App\Services\Security\HardeningScripts::ACTIONS[$fixAction]))
                @if (($finding->data['fix_status'] ?? null) === 'running')
                    <x-signal.ui.badge tone="info">{{ __('Fixing…') }}</x-signal.ui.badge>
                @else
                    <x-signal.ui.button :href="request()->fullUrlWithQuery(['dialog' => 'fix-'.$finding->id])" variant="secondary" size="sm" :data-modal-trigger="'fix-'.$finding->id">{{ __(\App\Services\Security\HardeningScripts::ACTIONS[$fixAction]) }}</x-signal.ui.button>
                    <x-signal.overlays.form-modal :id="'fix-'.$finding->id" :title="__(\App\Services\Security\HardeningScripts::ACTIONS[$fixAction])" :description="match ($fixAction) { 'reboot' => __('The server restarts in one minute; its websites are down while it boots, usually under a minute.'), 'enable-firewall' => __('Only SSH, the web (80 and 443) and the server’s own firewall rules stay open. Anything else listening on the server stops being reachable from outside.'), 'apply-updates' => __('Installs the waiting updates now. Services may restart briefly.'), default => __('This changes the server’s configuration. BuildPusher keeps its own access; the server is checked again afterwards.') }" :action="route('security.findings.fix', [$project, $finding->id])" :submit="__('Apply')" form-class="grid gap-4">
                        @if ($finding->data['fix_error'] ?? null)<x-signal.ui.alert tone="danger">{{ __('Last attempt failed: :error', ['error' => $finding->data['fix_error']]) }}</x-signal.ui.alert>@endif
                    </x-signal.overlays.form-modal>
                @endif
            @endif
            @if ($canManage && $finding->status === 'open')
                <x-signal.ui.button :href="request()->fullUrlWithQuery(['dialog' => 'ignore-'.$finding->id])" variant="quiet" size="sm" :data-modal-trigger="'ignore-'.$finding->id">{{ __('Ignore') }}</x-signal.ui.button>
                <x-signal.overlays.form-modal :id="'ignore-'.$finding->id" :title="__('Ignore this finding')" :description="__('It stays ignored through later scans until someone reopens it. Say why, for whoever looks next.')" :action="route('security.findings.update', [$project, $finding->id])" method="PUT" :submit="__('Ignore')" form-class="grid gap-4">
                    <input type="hidden" name="status" value="ignored">
                    <x-signal.ui.textarea-field :id="'ignore-reason-'.$finding->id" name="reason" :label="__('Why')" rows="3" maxlength="1000" :placeholder="__('A false positive, or a risk we accept because…')" />
                </x-signal.overlays.form-modal>
            @elseif ($canManage && $finding->status === 'ignored')
                <form method="POST" action="{{ route('security.findings.update', [$project, $finding->id]) }}">@csrf @method('PUT')<input type="hidden" name="status" value="open"><x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Reopen') }}</x-signal.ui.button></form>
            @endif
        </div>
    </div>
</li>
