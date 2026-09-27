@php($project = $overview->project)
@php($tone = fn (string $status): string => match ($status) { 'succeeded' => 'success', 'failed' => 'danger', 'canceled' => 'neutral', default => 'info' })

<x-signal.layouts.project :overview="$overview" :title="__('Commands on :server', ['server' => $server->label()])" :description="__('Commands run as root, one at a time, and time out after :seconds seconds. Output is stored encrypted and kept :days days.', ['seconds' => config('infrastructure.ssh_command_timeout'), 'days' => config('infrastructure.server_command_retention_days')])">
    @error('command')<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror
    <div class="flex flex-wrap gap-2">
        <x-signal.ui.button :href="route('infrastructure.servers.show', [$project, $server->id])" variant="quiet">{{ __('Back to :server', ['server' => $server->label()]) }}</x-signal.ui.button>
        @if ($canManage)
            <x-signal.ui.button :href="route('infrastructure.servers.commands.export', [$project, $server->id])" variant="secondary">{{ __('Export CSV') }}</x-signal.ui.button>
        @endif
    </div>

    @if ($canManage)
        <x-signal.ui.card>
            <form method="POST" action="{{ route('infrastructure.servers.commands.store', [$project, $server->id]) }}" class="grid gap-4 p-4 sm:p-6">
                @csrf
                <x-signal.ui.textarea-field name="command" :label="__('Command')" rows="3" maxlength="4096" class="font-mono" placeholder="systemctl status caddy --no-pager" required />
                <div><x-signal.ui.button type="submit" variant="primary" :disabled="$server->provisioning_status !== 'active'">{{ __('Run as root') }}</x-signal.ui.button></div>
            </form>
        </x-signal.ui.card>
    @endif

    @if ($selected)
        <x-signal.ui.settings-section :title="__('Command #:id', ['id' => $selected->id])" :description="$selected->status === 'queued' || $selected->status === 'running' ? __('Refresh the page to see the result.') : __('Exit code :code', ['code' => $selected->exit_code ?? '—'])">
            <div class="grid gap-3 p-4 sm:p-6">
                <x-signal.ui.code-block class="whitespace-pre-wrap" :code="'# '.$selected->command" />
                @if ($selected->output !== null)
                    <x-signal.ui.code-block class="max-h-96 overflow-auto whitespace-pre-wrap" :code="$selected->output" />
                @endif
            </div>
        </x-signal.ui.settings-section>
    @endif

    <form method="GET" action="{{ route('infrastructure.servers.commands', [$project, $server->id]) }}" class="flex flex-wrap items-end gap-3">
        <x-signal.ui.select-field name="status" :label="__('Status')">
            <option value="">{{ __('All') }}</option>
            @foreach (['queued', 'running', 'succeeded', 'failed', 'canceled'] as $option)
                <option value="{{ $option }}" @selected($status === $option)>{{ __(ucfirst($option)) }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <x-signal.ui.button type="submit" variant="secondary">{{ __('Filter') }}</x-signal.ui.button>
    </form>

    <x-signal.ui.table :caption="__('Command history')">
        <x-slot:head><tr><th scope="col">#</th><th scope="col">{{ __('Command') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Run') }}</th>@if ($canManage)<th scope="col"><span class="sr-only">{{ __('Actions') }}</span></th>@endif</tr></x-slot:head>
        @forelse ($executions as $execution)
            <tr>
                <td>{{ $execution->id }}</td>
                <td class="max-w-md"><a href="{{ route('infrastructure.servers.commands', [$project, $server->id, 'output' => $execution->id]) }}" class="break-all font-mono text-xs text-primary hover:underline">{{ \Illuminate\Support\Str::limit($execution->command, 120) }}</a></td>
                <td><x-signal.ui.badge :tone="$tone($execution->status)">{{ __(ucfirst($execution->status)) }}</x-signal.ui.badge>@if ($execution->exit_code !== null) <span class="text-xs text-muted">{{ __('exit :code', ['code' => $execution->exit_code]) }}</span>@endif</td>
                <td class="whitespace-nowrap text-xs text-muted">{{ $execution->created_at?->format('Y-m-d H:i') }}{{ " UTC" }}@if ($execution->user) · {{ $execution->user->name }}@endif</td>
                @if ($canManage)
                    <td class="whitespace-nowrap">
                        <div class="flex gap-1">
                            @if ($execution->status === 'queued')
                                <form method="POST" action="{{ route('infrastructure.servers.commands.cancel', [$project, $server->id, $execution->id]) }}">@csrf<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Cancel') }}</x-signal.ui.button></form>
                            @elseif ($execution->isFinished())
                                <form method="POST" action="{{ route('infrastructure.servers.commands.rerun', [$project, $server->id, $execution->id]) }}">@csrf<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Run again') }}</x-signal.ui.button></form>
                                <form method="POST" action="{{ route('infrastructure.servers.commands.destroy', [$project, $server->id, $execution->id]) }}">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Delete') }}</x-signal.ui.button></form>
                            @endif
                        </div>
                    </td>
                @endif
            </tr>
        @empty
            <tr><td colspan="5" class="py-8 text-center text-muted">{{ __('No commands yet.') }}</td></tr>
        @endforelse
    </x-signal.ui.table>
    @include('telemetry._pager', ['paginator' => $executions])
</x-signal.layouts.project>
