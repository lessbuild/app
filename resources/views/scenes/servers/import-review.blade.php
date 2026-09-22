<x-layouts.app>
    <x-layouts.partials.breadcrumbs :route="route('servers.import.create')" :title="__('Back to server inspection')" />
    <x-layouts.partials.heading
        icon="server"
        :title="__('Review import changes')"
        :description="__('No changes have been made to this server. Confirm its identity and the planned takeover before provisioning begins.')"
    />

    @php($report = $assessment->report)

    <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(20rem,.8fr)]">
        <div class="space-y-6">
            <section class="ui-card p-5 sm:p-6" aria-labelledby="server-import-discovery-heading">
                <p class="ui-eyebrow">{{ __('Read-only discovery') }}</p>
                <h2 id="server-import-discovery-heading" class="mt-2 text-xl font-black text-ink">{{ $report['hostname'] ?? __('Unknown host') }}</h2>
                <dl class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ([
                        [__('Operating system'), ($report['os_id'] ?? '—').' '.($report['os_version'] ?? '')],
                        [__('Architecture'), $report['architecture'] ?? '—'],
                        [__('Memory'), ($report['memory_mb'] ?? '—').' MB'],
                        [__('Free disk'), ($report['disk_free_mb'] ?? '—').' MB'],
                        [__('SSH algorithm'), $report['algorithm'] ?? '—'],
                        [__('Inspected'), $report['inspected_at'] ?? '—'],
                    ] as [$label, $value])
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-wide text-muted">{{ $label }}</dt>
                            <dd class="mt-1 break-words font-semibold text-ink">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
                <div class="mt-6 border-t border-line pt-5">
                    <h3 class="text-sm font-bold text-ink">{{ __('Detected services') }}</h3>
                    <p class="mt-1 text-sm text-muted">{{ ($report['services'] ?? []) !== [] ? implode(', ', $report['services']) : __('No managed service binaries detected.') }}</p>
                </div>
            </section>

            <section class="ui-card p-5 sm:p-6" aria-labelledby="server-import-identity-heading">
                <p class="ui-eyebrow">{{ __('Trust boundary') }}</p>
                <h2 id="server-import-identity-heading" class="mt-1 text-xl font-black text-ink">{{ __('SSH host identity') }}</h2>
                <p class="mt-2 text-sm leading-6 text-muted">{{ __('Compare this SHA-256 fingerprint with your provider console or a trusted existing SSH connection. :app will pin it and reject future connections if it changes.', ['app' => config('app.name')]) }}</p>
                <code class="library-code mt-4 block break-all">{{ $report['fingerprint'] }}</code>
            </section>

            <section class="ui-alert ui-alert--warning" aria-labelledby="server-import-impact-heading">
                <h2 id="server-import-impact-heading" class="font-black">{{ __('Changes provisioning may make') }}</h2>
                <ul class="mt-3 list-disc space-y-2 pl-5 text-sm">
                    <li>{{ __('Install and update operating-system packages for the selected server type.') }}</li>
                    <li>{{ __('Create :app-managed users, directories, credentials, services, firewall rules, and swap.', ['app' => config('app.name')]) }}</li>
                    <li>{{ __('Install or reconfigure the web server, language runtimes, databases, caches, workers, or load-balancer software included by the selected type.') }}</li>
                    <li>{{ __('Restart affected services. Existing configuration can be replaced where it conflicts with the managed configuration.') }}</li>
                </ul>
                @foreach ($report['warnings'] ?? [] as $warning)
                    <p class="mt-3 font-bold">⚠ {{ $warning }}</p>
                @endforeach
            </section>
        </div>

        <section class="ui-card h-fit p-5 sm:p-6" aria-labelledby="server-import-approve-heading">
            <p class="ui-eyebrow">{{ __('Explicit approval') }}</p>
            <h2 id="server-import-approve-heading" class="mt-1 text-xl font-black text-ink">{{ __('Approve takeover') }}</h2>
            <p class="mt-2 text-sm leading-6 text-muted">{{ __('This inspection expires :time. If anything changed after inspection, go back and inspect again.', ['time' => $assessment->expires_at->diffForHumans()]) }}</p>

            @if ($errors->any())
                <x-ui.alert tone="danger" class="mt-4">
                    <ul class="list-disc space-y-1 pl-5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </x-ui.alert>
            @endif

            <form method="POST" action="{{ route('servers.import.confirm', $assessment) }}" class="mt-6 space-y-5">
                @csrf
                <label class="flex items-start gap-3">
                    <input type="checkbox" name="host_fingerprint_confirmed" value="1" required class="ui-check mt-1">
                    <span class="text-sm text-muted">{{ __('I verified the SSH fingerprint through a trusted source.') }}</span>
                </label>
                <label class="flex items-start gap-3">
                    <input type="checkbox" name="backup_confirmed" value="1" required class="ui-check mt-1">
                    <span class="text-sm text-muted">{{ __('I have a current backup or disposable server snapshot and understand existing configuration may change.') }}</span>
                </label>
                <div>
                    <label for="confirmation" class="ui-label">{{ __('Type :name to approve', ['name' => $assessment->configuration['name']]) }}</label>
                    <input id="confirmation" name="confirmation" required autocomplete="off" class="ui-input">
                </div>
                <x-ui.button type="submit" variant="primary" class="w-full">{{ __('Approve and begin provisioning') }}</x-ui.button>
            </form>
        </section>
    </div>
</x-layouts.app>
