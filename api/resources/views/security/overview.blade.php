@php($project = $overview->project)
@php($gradeTone = match ($security['grade']) { 'A' => 'success', 'B' => 'info', 'C' => 'warning', default => 'danger' })

<x-signal.layouts.project :overview="$overview" :title="__('Security')" :description="__('Vulnerable packages, leaked secrets, server hardening, domains and attacks, checked every :hours hours on your plan.', ['hours' => $security['intervalHours']])">
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-signal.ui.card class="p-5">
            <p class="text-xs font-bold text-muted">{{ __('Security score') }}</p>
            <p class="mt-2 flex items-baseline gap-3"><span class="text-4xl font-extrabold tracking-tight text-ink tabular-nums">{{ $security['score'] }}</span><x-signal.ui.badge :tone="$gradeTone">{{ __('Grade :grade', ['grade' => $security['grade']]) }}</x-signal.ui.badge></p>
        </x-signal.ui.card>
        @foreach (['critical', 'high', 'medium'] as $severity)
            <x-signal.ui.card class="p-5">
                <p class="text-xs font-bold text-muted">{{ __(\App\Models\SecurityFinding::SEVERITIES[$severity]['label']) }}</p>
                <p class="mt-2 text-3xl font-extrabold tracking-tight text-ink tabular-nums"><a class="hover:underline" href="{{ route('security.findings', [$project, 'severity' => $severity]) }}">{{ number_format($security['bySeverity'][$severity] ?? 0) }}</a></p>
            </x-signal.ui.card>
        @endforeach
    </div>

    <x-signal.ui.settings-section :title="__('Checks')" :description="__('Each check runs on its own schedule. Run one now after fixing something to see it clear.')">
        <ul class="divide-y divide-line">
            @forelse ($security['checks'] as $check)
                <li class="flex flex-wrap items-center justify-between gap-3 p-4 sm:px-6">
                    <div>
                        <p class="font-bold text-ink">{{ $check['label'] }}</p>
                        <p class="text-sm text-muted">
                            @if (! $check['included'])
                                {{ __('Not on your plan.') }} <a class="ui-link" href="{{ route('account.billing', ['tab' => 'security']) }}">{{ __('See plans') }}</a>
                            @elseif ($check['last'] === null)
                                {{ __('Not run yet.') }}
                            @elseif (in_array($check['last']->status, ['queued', 'running'], true))
                                {{ __('Running now…') }}
                            @elseif ($check['last']->status === 'failed')
                                <span class="text-danger">{{ __('Failed :time: :error', ['time' => $check['last']->finished_at?->diffForHumans(), 'error' => $check['last']->error]) }}</span>
                            @else
                                {{ trans_choice('Ran :time · :count open finding|Ran :time · :count open findings', $check['last']->findings_count, ['time' => $check['last']->finished_at?->diffForHumans(), 'count' => $check['last']->findings_count]) }}
                            @endif
                        </p>
                    </div>
                    @if ($canManage && $check['included'])
                        <form method="POST" action="{{ route('security.scans.store', $project) }}">
                            @csrf
                            <input type="hidden" name="kind" value="{{ $check['kind'] }}">
                            <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Scan now') }}</x-signal.ui.button>
                        </form>
                    @endif
                </li>
            @empty
                <li class="p-4 text-sm text-muted sm:px-6">{{ __('No checks are available yet.') }}</li>
            @endforelse
        </ul>
    </x-signal.ui.settings-section>

    @if ($environments->isNotEmpty())
        <x-signal.ui.settings-section id="deploy-gate" :title="__('Deploy gate')" :description="__('Stop a deploy before it goes live when its packages have a known vulnerability at or above the level you choose. Ignoring a finding in Security accepts the risk and lets deploys through.')">
            <ul class="divide-y divide-line">
                @foreach ($environments as $environment)
                    <li class="flex flex-wrap items-center justify-between gap-3 p-4 sm:px-6">
                        <p class="font-bold text-ink">{{ $environment->name }}</p>
                        @if ($canManage && $gateIncluded)
                            <form method="POST" action="{{ route('security.gate.update', [$project, $environment->id]) }}" class="flex items-end gap-2">
                                @csrf @method('PUT')
                                <x-signal.ui.select-field :id="'gate-'.$environment->id" name="security_gate" :label="__('Block deploys with')" :show-errors="false">
                                    <option value="" @selected($environment->security_gate === null)>{{ __('Nothing (off)') }}</option>
                                    <option value="critical" @selected($environment->security_gate === 'critical')>{{ __('Critical vulnerabilities') }}</option>
                                    <option value="high" @selected($environment->security_gate === 'high')>{{ __('High or critical vulnerabilities') }}</option>
                                </x-signal.ui.select-field>
                                <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Save') }}</x-signal.ui.button>
                            </form>
                        @else
                            <span class="text-sm text-muted">{{ match ($environment->security_gate) { 'critical' => __('Blocks critical vulnerabilities'), 'high' => __('Blocks high and critical vulnerabilities'), default => $gateIncluded ? __('Off') : __('Comes with Pro and above') } }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-signal.ui.settings-section>
    @endif

    <x-signal.ui.card as="section" class="p-5 sm:p-6" aria-labelledby="top-findings">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="top-findings" class="text-lg font-extrabold text-ink">{{ __('Most serious open findings') }}</h2>
            <x-signal.ui.button :href="route('security.findings', $project)" variant="quiet" size="sm">{{ __('All findings') }}</x-signal.ui.button>
        </div>
        @if ($security['recent']->isEmpty())
            <p class="mt-4 text-sm text-muted">{{ __('Nothing open. Checks keep running in the background.') }}</p>
        @else
            <ul class="mt-2 divide-y divide-line">
                @foreach ($security['recent'] as $finding)
                    @include('security._finding')
                @endforeach
            </ul>
        @endif
    </x-signal.ui.card>
</x-signal.layouts.project>
