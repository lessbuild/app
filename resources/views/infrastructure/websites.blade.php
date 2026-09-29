@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Websites')" :description="__('Sites on your app servers: a Caddy site with HTTPS, a MySQL database and a .env file each.')">
    @if ($canManage)
        <div class="flex flex-wrap items-center justify-end gap-3">
            @if ($limit !== null)
                <span class="text-sm text-muted">{{ __(':used of :limit websites on your plan', ['used' => $websites->count(), 'limit' => $limit]) }}</span>
            @endif
            <x-signal.ui.button :href="route('infrastructure.websites.create', [$project, 'import' => 1])" variant="secondary" data-modal-trigger="import-website" :data-modal-history-url="route('infrastructure.websites', [$project, 'dialog' => 'import-website'])">{{ __('Import a website') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('infrastructure.websites.create', $project)" variant="primary" data-modal-trigger="create-website" :data-modal-history-url="route('infrastructure.websites', [$project, 'dialog' => 'create-website'])">{{ __('Create a website') }}</x-signal.ui.button>
        </div>

        @if ($hosts->isEmpty())
            @foreach (['create-website' => __('Create a website'), 'import-website' => __('Import a website')] as $id => $title)
                <x-signal.overlays.modal :id="$id" :title="$title">
                    <x-signal.ui.empty-state icon="server" :title="__('No app servers ready')" :description="__('Websites need an active app server with MySQL. Create one first.')">
                        <x-slot:action><x-signal.ui.button :href="route('infrastructure.servers', [$project, 'dialog' => 'create-server'])" variant="primary">{{ __('Create a server') }}</x-signal.ui.button></x-slot:action>
                    </x-signal.ui.empty-state>
                </x-signal.overlays.modal>
            @endforeach
        @else
            <x-signal.overlays.form-modal id="create-website" :title="__('Create a website')" :description="__('We set up the Caddy site, a MySQL database and user, and the .env file.')" :action="route('infrastructure.websites.store', $project)" :submit="__('Create website')" form-class="grid items-start gap-5 sm:grid-cols-2">
                <div class="sm:col-span-2"><x-signal.ui.plan-limit-alert service="deploy" /></div>
                @include('infrastructure._website-fields', ['website' => null])
            </x-signal.overlays.form-modal>
            <x-signal.overlays.form-modal id="import-website" :title="__('Import a website')" :description="__('Adopt an application already in /var/www on an app server. Its files, Caddy site and database are left as they are.')" :action="route('infrastructure.websites.import', $project)" :submit="__('Import website')" form-class="grid items-start gap-5 sm:grid-cols-2">
                <x-signal.ui.input-field id="import-name" name="name" :label="__('Name')" maxlength="255" required />
                <x-signal.ui.select-field id="import-server" name="server_id" :label="__('Server')" required>
                    @foreach ($hosts as $host)
                        <option value="{{ $host->id }}" @selected((int) old('server_id') === $host->id)>{{ $host->label() }} · {{ $host->public_ip }}</option>
                    @endforeach
                </x-signal.ui.select-field>
                <x-signal.ui.input-field id="import-url" name="url" :label="__('Domain')" maxlength="255" placeholder="shop.example.com" required />
                <x-signal.ui.input-field id="import-slug" name="deployment_slug" :label="__('Directory in /var/www')" maxlength="32" placeholder="shop" :description="__('Lowercase letters, numbers and dashes.')" required />
                <div class="sm:col-span-2"><x-signal.ui.textarea-field id="import-description" name="description" :label="__('Description')" rows="2" maxlength="2000" /></div>
            </x-signal.overlays.form-modal>
        @endif
    @endif

    @if ($websites->isEmpty())
        <x-signal.ui.empty-state icon="globe" :title="__('No websites yet')" :description="__('Create one on an app server, or import an application already under /var/www.')" />
    @else
        <div id="websites-live" data-live-region data-live-interval="10000">
            <x-signal.ui.table :caption="__('Websites')">
                <x-slot:head><tr><th scope="col">{{ __('Website') }}</th><th scope="col">{{ __('Domain') }}</th><th scope="col">{{ __('Server') }}</th><th scope="col">{{ __('Status') }}</th></tr></x-slot:head>
                @foreach ($websites as $website)
                    <tr>
                        <td><a href="{{ route('infrastructure.websites.show', [$project, $website->id]) }}" class="font-bold text-primary hover:underline">{{ $website->name }}</a></td>
                        <td class="font-mono text-xs">{{ $website->url }}</td>
                        <td class="text-muted">{{ $website->server?->label() ?? '—' }}</td>
                        <td>@include('infrastructure._website-status', ['website' => $website])</td>
                    </tr>
                @endforeach
            </x-signal.ui.table>
        </div>
    @endif
</x-signal.layouts.project>
