<x-layouts.app>
    <x-layouts.partials.breadcrumbs
        :route="route('servers.show', $server)"
        :title="__('Back to :server', ['server' => $server->label])"
    />

    <x-layouts.partials.heading
        icon="terminal"
        :title="__('Command history')"
        :description="__('Review commands queued for :server and download their retained output.', ['server' => $server->label])"
    />

    <form method="GET" action="{{ route('servers.commands.index', $server) }}" class="mb-6 rounded-lg border border-primary bg-primary p-4">
        @error('command')
            <p class="mb-4 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-700">{{ $message }}</p>
        @enderror
        <div class="grid gap-4 md:grid-cols-3">
            <div class="min-w-64 flex-1">
                <label for="status" class="block text-xs font-semibold uppercase text-secondary">{{ __('Status') }}</label>
                <select id="status" name="status" class="input secondary mt-1 w-full rounded">
                    <option value="">{{ __('All statuses') }}</option>
                    @foreach ($statuses as $option)
                        <option value="{{ $option }}" @selected($filters['status'] === $option)>
                            {{ str($option)->title() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="date_from" class="block text-xs font-semibold uppercase text-secondary">{{ __('Queued from') }}</label>
                <input
                    id="date_from"
                    name="date_from"
                    type="date"
                    value="{{ $filters['date_from'] }}"
                    class="input secondary mt-1 w-full rounded"
                >
            </div>
            <div>
                <label for="date_to" class="block text-xs font-semibold uppercase text-secondary">{{ __('Queued through') }}</label>
                <input
                    id="date_to"
                    name="date_to"
                    type="date"
                    value="{{ $filters['date_to'] }}"
                    class="input secondary mt-1 w-full rounded"
                >
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <button type="submit" class="button primary">{{ __('Apply filter') }}</button>
            <a href="{{ route('servers.commands.export', [$server, ...array_filter($filters, fn ($value) => $value !== null)]) }}" class="button primary">{{ __('Export CSV') }}</a>
            @if (array_filter($filters, fn ($value) => $value !== null))
                <a href="{{ route('servers.commands.index', $server) }}" class="button primary">{{ __('Clear filter') }}</a>
            @endif
        </div>
    </form>

    <dl class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Matching commands') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['total'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Commands in this filtered view.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Active commands') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['active'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Queued or running commands.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Succeeded') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['succeeded'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Matching successful commands.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Failed') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['failed'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Matching failed commands.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Canceled') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['canceled'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Matching canceled commands.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Output retained') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['output'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Matching commands with downloadable output.') }}</dd>
        </div>
    </dl>

    <div class="overflow-x-auto rounded-lg border border-primary bg-primary">
        <table class="min-w-full divide-y divide-primary">
            <thead class="bg-secondary">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-secondary">{{ __('Command') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-secondary">{{ __('Status') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-secondary">{{ __('Timing') }}</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-secondary">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-primary">
                @forelse ($executions as $execution)
                    <tr>
                        <td class="max-w-xl px-4 py-4 align-top">
                            <code class="break-all text-xs text-primary">{{ $execution->command }}</code>
                            @if ($execution->exit_code !== null)
                                <p class="mt-2 text-xs text-secondary">{{ __('Exit code: :code', ['code' => $execution->exit_code]) }}</p>
                            @endif
                            @if ($execution->rerun_from_execution_id)
                                <p class="mt-2 text-xs text-secondary">{{ __('Rerun of command #:id', ['id' => $execution->rerun_from_execution_id]) }}</p>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-4 align-top text-xs font-semibold uppercase text-secondary">
                            {{ $execution->status }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-4 align-top text-xs text-secondary">
                            <span class="block">{{ __('Queued :time', ['time' => $execution->created_at->diffForHumans()]) }}</span>
                            @if ($execution->started_at)
                                <span class="mt-1 block">{{ __('Started :time', ['time' => $execution->started_at->diffForHumans()]) }}</span>
                            @endif
                            @if ($execution->finished_at)
                                <span class="mt-1 block">{{ __('Finished :time', ['time' => $execution->finished_at->diffForHumans()]) }}</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-4 text-right align-top text-sm">
                            <div class="flex justify-end gap-2">
                                @if ($execution->output !== null)
                                    <a href="{{ route('servers.commands.output', ['server' => $server, 'execution' => $execution]) }}" class="button primary">
                                        {{ __('Download output') }}
                                    </a>
                                @endif
                                @if ($execution->status === \App\Models\ServerCommandExecution::STATUS_QUEUED)
                                    <form method="POST" action="{{ route('servers.commands.cancel', ['server' => $server, 'execution' => $execution]) }}">
                                        @csrf
                                        <button type="submit" class="button primary" onclick="return confirm({{ Illuminate\Support\Js::from(__('Cancel this queued command?')) }})">
                                            {{ __('Cancel') }}
                                        </button>
                                    </form>
                                @endif
                                @if ($server->provisioning_status === \App\Models\Server::STATUS_ACTIVE
                                    && in_array($execution->status, \App\Models\ServerCommandExecution::TERMINAL_STATUSES, true))
                                    <form method="POST" action="{{ route('servers.commands.rerun', ['server' => $server, 'execution' => $execution]) }}">
                                        @csrf
                                        <button type="submit" class="button primary" onclick="return confirm({{ Illuminate\Support\Js::from(__('Run this command again as root?')) }})">
                                            {{ __('Run again') }}
                                        </button>
                                    </form>
                                @endif
                                @if (in_array($execution->status, \App\Models\ServerCommandExecution::TERMINAL_STATUSES, true))
                                    <form method="POST" action="{{ route('servers.commands.destroy', ['server' => $server, 'execution' => $execution]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="button primary" onclick="return confirm({{ Illuminate\Support\Js::from(__('Delete this command and its retained output?')) }})">
                                            {{ __('Delete') }}
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-6 py-10 text-center">
                            <p class="font-medium text-primary">
                                {{ array_filter($filters, fn ($value) => $value !== null) ? __('No commands match these filters') : __('No commands have been run on this server yet') }}
                            </p>
                            @if (array_filter($filters, fn ($value) => $value !== null))
                                <a href="{{ route('servers.commands.index', $server) }}" class="mt-2 inline-block text-sm text-ternary hover:underline">
                                    {{ __('Clear filter') }}
                                </a>
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">
        {{ $executions->links() }}
    </div>
</x-layouts.app>
