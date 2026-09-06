<x-layouts.app>
    <x-layouts.partials.breadcrumbs :route="route('servers.import.create')" :title="__('Back to server inspection')" />
    <x-layouts.partials.heading icon="server" :title="__('Review import changes')" :description="__('No changes have been made to this server. Confirm its identity and the planned takeover before provisioning begins.')" />
    @php($report = $assessment->report)
    <div class="mt-6 grid gap-6 xl:grid-cols-[1fr_.8fr]">
        <div class="space-y-6">
            <section class="rounded-2xl border border-primary bg-primary p-6">
                <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Read-only discovery') }}</p>
                <h2 class="mt-2 text-xl font-black text-primary">{{ $report['hostname'] ?? __('Unknown host') }}</h2>
                <dl class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach([[__('Operating system'), ($report['os_id'] ?? '—').' '.($report['os_version'] ?? '')], [__('Architecture'), $report['architecture'] ?? '—'], [__('Memory'), ($report['memory_mb'] ?? '—').' MB'], [__('Free disk'), ($report['disk_free_mb'] ?? '—').' MB'], [__('SSH algorithm'), $report['algorithm'] ?? '—'], [__('Inspected'), $report['inspected_at'] ?? '—']] as [$label,$value])
                        <div><dt class="text-xs font-bold uppercase text-secondary">{{ $label }}</dt><dd class="mt-1 font-semibold text-primary">{{ $value }}</dd></div>
                    @endforeach
                </dl>
                <div class="mt-5"><h3 class="text-sm font-bold text-primary">{{ __('Detected services') }}</h3><p class="mt-1 text-sm text-secondary">{{ ($report['services'] ?? []) !== [] ? implode(', ', $report['services']) : __('No managed service binaries detected.') }}</p></div>
            </section>
            <section class="rounded-2xl border border-primary bg-primary p-6">
                <h2 class="text-xl font-black text-primary">{{ __('SSH host identity') }}</h2>
                <p class="mt-2 text-sm leading-6 text-secondary">{{ __('Compare this SHA-256 fingerprint with your provider console or a trusted existing SSH connection. BuildPusher will pin it and reject future connections if it changes.') }}</p>
                <code class="mt-4 block break-all rounded-xl bg-secondary p-4 text-sm font-bold text-primary">{{ $report['fingerprint'] }}</code>
            </section>
            <section class="rounded-2xl border border-amber-300 bg-amber-50 p-6 text-amber-950">
                <h2 class="font-black">{{ __('Changes provisioning may make') }}</h2>
                <ul class="mt-3 list-disc space-y-2 pl-5 text-sm"><li>{{ __('Install and update operating-system packages for the selected server type.') }}</li><li>{{ __('Create BuildPusher-managed users, directories, credentials, services, firewall rules, and swap.') }}</li><li>{{ __('Install or reconfigure the web server, language runtimes, databases, caches, workers, or load-balancer software included by the selected type.') }}</li><li>{{ __('Restart affected services. Existing configuration can be replaced where it conflicts with the managed configuration.') }}</li></ul>
                @foreach($report['warnings'] ?? [] as $warning)<p class="mt-3 font-bold">⚠ {{ $warning }}</p>@endforeach
            </section>
        </div>
        <section class="h-fit rounded-2xl border border-primary bg-primary p-6">
            <h2 class="text-xl font-black text-primary">{{ __('Approve takeover') }}</h2><p class="mt-2 text-sm leading-6 text-secondary">{{ __('This inspection expires :time. If anything changed after inspection, go back and inspect again.', ['time'=>$assessment->expires_at->diffForHumans()]) }}</p>
            @if($errors->any())<div role="alert" class="mt-4 rounded-lg bg-red-50 p-4 text-sm text-red-800"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            <form method="POST" action="{{ route('servers.import.confirm', $assessment) }}" class="mt-6 space-y-5">@csrf
                <label class="flex gap-3"><input type="checkbox" name="host_fingerprint_confirmed" value="1" required><span class="text-sm text-secondary">{{ __('I verified the SSH fingerprint through a trusted source.') }}</span></label>
                <label class="flex gap-3"><input type="checkbox" name="backup_confirmed" value="1" required><span class="text-sm text-secondary">{{ __('I have a current backup or disposable server snapshot and understand existing configuration may change.') }}</span></label>
                <label><span class="block text-sm font-bold text-primary">{{ __('Type :name to approve', ['name'=>$assessment->configuration['name']]) }}</span><input name="confirmation" required autocomplete="off" class="input secondary mt-2 rounded"></label>
                <button type="submit" class="button primary w-full justify-center">{{ __('Approve and begin provisioning') }}</button>
            </form>
        </section>
    </div>
</x-layouts.app>
