{{-- The Services tab: search engines and a cache installed with one click, with their addresses and keys. --}}
<x-signal.ui.settings-section :title="__('Services')" :description="__('Install a search engine or a cache on this server. Each gets a generated key or password and listens on the server itself; share it over the private network to use it from your other servers.')">
    <div class="grid gap-4 p-4 sm:p-6">
        @error('listen')<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror
        @foreach (\App\Models\ServerService::KINDS as $kind => $info)
            @php($task = $services->get($kind))
            <div class="grid gap-3 border-b border-line pb-4 last:border-0 last:pb-0 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start">
                <div class="min-w-0">
                    <p class="font-bold text-ink">{{ $info['name'] }}</p>
                    <p class="text-sm text-muted">{{ __($info['description']) }}</p>
                    @if ($task)
                        @include('infrastructure.server._task-status')
                        @if ($task->status === 'active')
                            <dl class="mt-2 grid gap-1 text-xs">
                                <div><dt class="inline font-bold">{{ __('Address') }}:</dt> <dd class="inline font-mono">{{ $task->localAddress() }}@if ($task->listen === 'private' && $server->private_ip) · {{ str_replace('127.0.0.1', $server->private_ip, $task->localAddress()) }}@endif</dd></div>
                                <div><dt class="inline font-bold">{{ __($info['secret']) }}:</dt> <dd class="inline"><details class="inline"><summary class="inline cursor-pointer text-primary underline">{{ __('Show') }}</summary> <code class="font-mono break-all">{{ $task->secret }}</code></details></dd></div>
                            </dl>
                        @endif
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <form method="POST" action="{{ route('infrastructure.servers.services.install', [$project, $server->id]) }}" class="flex flex-wrap items-center gap-2">
                        @csrf
                        <input type="hidden" name="kind" value="{{ $kind }}">
                        <x-signal.ui.select-field :id="'listen-'.$kind" name="listen" :label="__('Reachable from')" :show-errors="false">
                            <option value="local" @selected(($task?->listen ?? 'local') === 'local')>{{ __('This server') }}</option>
                            <option value="private" @selected($task?->listen === 'private') @disabled($server->private_ip === null)>{{ __('Private network too') }}</option>
                        </x-signal.ui.select-field>
                        <x-signal.ui.button type="submit" :variant="$task ? 'secondary' : 'primary'" size="sm" :disabled="$task && in_array($task->status, ['pending', 'removing'], true)">{{ $task ? __('Apply') : __('Install') }}</x-signal.ui.button>
                    </form>
                    @if ($task && $task->status !== 'removing')
                        <form method="POST" action="{{ route('infrastructure.servers.tasks.destroy', [$project, $server->id, 'services', $task->id]) }}">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Uninstall') }}</x-signal.ui.button></form>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</x-signal.ui.settings-section>
