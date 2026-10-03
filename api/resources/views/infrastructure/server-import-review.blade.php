@php($project = $overview->project)
@php($report = $assessment->report)

<x-signal.layouts.project :overview="$overview" :title="__('Review the import')" :description="__('What we found on :ip. Nothing has been changed yet.', ['ip' => $assessment->configuration['public_ip']])">
    @foreach (['confirmation', 'plan'] as $key)
        @error($key)<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror
    @endforeach
    <x-signal.ui.card class="grid gap-4 p-5">
        <dl class="grid gap-4 text-sm sm:grid-cols-3">
            <div><dt class="text-xs text-muted">{{ __('Hostname') }}</dt><dd class="mt-1">{{ $report['hostname'] ?? '—' }}</dd></div>
            <div><dt class="text-xs text-muted">{{ __('System') }}</dt><dd class="mt-1">Ubuntu {{ $report['os_version'] ?? '?' }} · {{ $report['architecture'] ?? '?' }}</dd></div>
            <div><dt class="text-xs text-muted">{{ __('Memory · free disk') }}</dt><dd class="mt-1">{{ number_format((int) ($report['memory_mb'] ?? 0)) }} MB · {{ number_format((int) ($report['disk_free_mb'] ?? 0)) }} MB</dd></div>
            <div class="sm:col-span-3"><dt class="text-xs text-muted">{{ __('Host key') }}</dt><dd class="mt-1 break-all font-mono text-xs">{{ $report['fingerprint'] ?? '—' }}</dd></div>
            <div class="sm:col-span-3"><dt class="text-xs text-muted">{{ __('Services found') }}</dt><dd class="mt-1">{{ ($report['services'] ?? []) === [] ? __('None') : implode(', ', $report['services']) }}</dd></div>
        </dl>
        @foreach ($report['warnings'] ?? [] as $warning)
            <x-signal.ui.alert tone="warning">{{ $warning }}</x-signal.ui.alert>
        @endforeach
    </x-signal.ui.card>
    @if ($usable)
        <form method="POST" action="{{ route('infrastructure.imports.confirm', [$project, $assessment->id]) }}" class="flex flex-wrap gap-3">
            @csrf
            <x-signal.ui.button type="submit" variant="primary">{{ __('Import and provision') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('infrastructure.servers', $project)" variant="quiet">{{ __('Cancel') }}</x-signal.ui.button>
        </form>
        <p class="text-xs text-muted">{{ __('This review expires :time.', ['time' => $assessment->expires_at->diffForHumans()]) }}</p>
    @else
        <x-signal.ui.alert tone="info">{{ __('This review expired or was already used. Inspect the server again.') }}</x-signal.ui.alert>
    @endif
</x-signal.layouts.project>
