@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Servers')" :description="__('Servers belong to :account, so every project can deploy to them.', ['account' => $project->account->name])">
    @if ($canManage)
        <div class="flex flex-wrap items-center justify-end gap-3">
            @if ($limit !== null)
                <span class="text-sm text-muted">{{ __(':used of :limit servers on your plan', ['used' => $servers->count(), 'limit' => $limit]) }}</span>
            @endif
            <x-signal.ui.button :href="route('infrastructure.imports.create', $project)" variant="secondary" data-modal-trigger="import-server" :data-modal-history-url="route('infrastructure.servers', [$project, 'dialog' => 'import-server'])">{{ __('Import a server') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('infrastructure.servers.create', $project)" variant="primary" data-modal-trigger="create-server" :data-modal-history-url="route('infrastructure.servers', [$project, 'dialog' => 'create-server'])">{{ __('Create a server') }}</x-signal.ui.button>
        </div>
        <x-signal.overlays.modal id="create-server" :title="__('Create a server')" :description="__('The server provisions itself with the software its type needs. It takes about ten minutes.')">
            {{-- Loaded when the modal opens (reading the provider's regions and sizes takes a moment); after a failed
                 submit it's rendered here so the errors show. --}}
            <div data-fragment-src="{{ route('infrastructure.servers.create', $project) }}" data-fragment-fallback="{{ __('Couldn’t load the form here. Open it on its own page.') }}" @if ($createForm !== null) data-fragment-loaded="true" @endif class="grid gap-5">
                @if ($createForm !== null)
                    @include('infrastructure._server-create-form', [...$createForm, 'inModal' => true])
                @else
                    <p class="text-sm text-muted" role="status">{{ __('Loading regions and sizes…') }}</p>
                @endif
            </div>
        </x-signal.overlays.modal>
        <x-signal.overlays.form-modal id="import-server" :title="__('Import a server')" :description="__('We connect over SSH, look around without changing anything, and show what we found before you confirm.')" :action="route('infrastructure.imports.store', $project)" :submit="__('Inspect server')" form-class="grid items-start gap-5 sm:grid-cols-2">
            @foreach (['plan', 'connection'] as $key)
                @error($key)<div class="sm:col-span-2"><x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert></div>@enderror
            @endforeach
            @include('infrastructure._server-import-fields', ['types' => \App\Enums\ServerType::cases()])
        </x-signal.overlays.form-modal>
    @endif

    @if ($servers->isEmpty())
        <x-signal.ui.empty-state icon="server" :title="__('No servers yet')" :description="__('Create one at DigitalOcean, Hetzner Cloud or Vultr, or import an Ubuntu server you already run.')" />
    @else
        <div id="servers-live" data-live-region data-live-interval="10000">
            <x-signal.ui.table :caption="__('Servers')">
                <x-slot:head><tr><th scope="col">{{ __('Server') }}</th><th scope="col">{{ __('Type') }}</th><th scope="col">{{ __('Address') }}</th><th scope="col">{{ __('Where') }}</th><th scope="col">{{ __('Status') }}</th></tr></x-slot:head>
                @foreach ($servers as $server)
                    <tr>
                        <td><a href="{{ route('infrastructure.servers.show', [$project, $server->id]) }}" class="font-bold text-primary hover:underline">{{ $server->label() }}</a></td>
                        <td>{{ $server->type->label() }}</td>
                        <td class="font-mono text-xs">{{ $server->public_ip ?? '—' }}</td>
                        <td class="text-muted">{{ $server->provider?->type->label() ?? __('Imported') }}@if ($server->region) · {{ $server->region }}@endif</td>
                        <td>@include('infrastructure._status', ['server' => $server])</td>
                    </tr>
                @endforeach
            </x-signal.ui.table>
        </div>
    @endif
</x-signal.layouts.project>
