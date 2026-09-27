@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="$server->label()" :description="$server->type->label().($server->public_ip ? ' · '.$server->public_ip : '').($server->provider ? ' · '.$server->provider->type->label().($server->region ? ' '.$server->region : '') : ' · '.__('Imported'))">
    @if (session('error'))
        <x-signal.ui.alert tone="danger" role="alert">{{ session('error') }}</x-signal.ui.alert>
    @endif
    @foreach (['retry', 'server'] as $key)
        @error($key)<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror
    @endforeach
    @include('infrastructure._secrets')

    <x-signal.ui.card class="grid gap-4 p-5">
        <div class="flex flex-wrap items-center gap-3">
            @include('infrastructure._status', ['server' => $server])
            @if ($server->isProvisioning())
                <span class="text-sm text-muted">{{ __('Stage :stage of :final', ['stage' => $server->setup_stage, 'final' => $finalStage]) }}</span>
            @elseif ($server->provisioned_at)
                <span class="text-sm text-muted">{{ __('Provisioned :time', ['time' => $server->provisioned_at->diffForHumans()]) }}</span>
            @endif
        </div>
        @if ($server->isProvisioning())
            <x-signal.ui.progress :value="$server->setup_stage" :max="$finalStage" :label="__('Provisioning progress')" />
        @endif
        @if ($server->provisioning_status === 'failed')
            <p class="text-sm text-danger">{{ $server->provisioning_error }}</p>
            @if ($canManage)
                <div class="flex flex-wrap gap-2">
                    @if ($server->provisioning_failure_phase === 'initialization')
                        <form method="POST" action="{{ route('infrastructure.servers.initialization.retry', [$project, $server->id]) }}">@csrf<x-signal.ui.button type="submit" variant="secondary">{{ __('Retry initialisation') }}</x-signal.ui.button></form>
                    @elseif ($server->provisioning_failure_phase === 'remote')
                        <form method="POST" action="{{ route('infrastructure.servers.provisioning.retry', [$project, $server->id]) }}">@csrf<x-signal.ui.button type="submit" variant="secondary">{{ __('Retry provisioning') }}</x-signal.ui.button></form>
                    @else
                        <p class="text-sm text-muted">{{ __('Creation failed and nothing was left running. Delete this server and try again.') }}</p>
                    @endif
                </div>
            @endif
        @endif
        <dl class="grid gap-4 text-sm sm:grid-cols-3">
            <div><dt class="text-xs text-muted">{{ __('SSH') }}</dt><dd class="mt-1 font-mono">{{ 'root@'.($server->public_ip ?? '—').':'.$server->ssh_port }}</dd></div>
            <div><dt class="text-xs text-muted">{{ __('Host key') }}</dt><dd class="mt-1 break-all font-mono text-xs">{{ $server->ssh_host_fingerprint ?? '—' }}</dd></div>
            <div><dt class="text-xs text-muted">{{ __('Size · image') }}</dt><dd class="mt-1">{{ $server->size ?? '—' }} · {{ $server->image ?? '—' }}</dd></div>
        </dl>
    </x-signal.ui.card>

    @if ($log && ($log->log || $log->error))
        <x-signal.ui.settings-section :title="__('Provisioning log')" :description="$log->refreshed_at ? __('Received :time', ['time' => $log->refreshed_at->diffForHumans()]) : __('Waiting for the server.')">
            <x-signal.ui.code-block class="m-4 max-h-96 overflow-auto whitespace-pre-wrap sm:m-6" :code="$log->log ?? $log->error ?? ''" />
        </x-signal.ui.settings-section>
    @endif

    @if ($canManage)
        <x-signal.ui.settings-section :title="__('Name')" :description="__('Shown in the app. The server’s hostname stays :name.', ['name' => $server->name])">
            <form method="POST" action="{{ route('infrastructure.servers.update', [$project, $server->id]) }}" class="flex flex-wrap items-end gap-3 p-4 sm:p-6">
                @csrf
                @method('PUT')
                <x-signal.ui.input-field name="display_name" :label="__('Display name')" :value="$server->display_name" maxlength="80" />
                <x-signal.ui.button type="submit" variant="secondary">{{ __('Save') }}</x-signal.ui.button>
            </form>
        </x-signal.ui.settings-section>

        <x-signal.ui.settings-section :title="__('Delete this server')" :description="$server->provider ? __(':provider deletes the machine and everything on it, and the SSH key we made.', ['provider' => $server->provider->type->label()]) : __('The server is forgotten here; nothing on it is changed.')">
            <div class="p-4 sm:p-6">
                <x-signal.ui.button variant="danger" data-modal-trigger="delete-server">{{ __('Delete server') }}</x-signal.ui.button>
                <x-signal.overlays.delete-confirmation id="delete-server" :route="route('infrastructure.servers.destroy', [$project, $server->id])" :title="__('Delete :server?', ['server' => $server->label()])" :description="$server->provider ? __('The machine and its data are deleted at the provider. This can’t be undone.') : __('Nothing on the server is changed.')" :submit-label="__('Delete server')" />
            </div>
        </x-signal.ui.settings-section>
    @endif
</x-signal.layouts.project>
