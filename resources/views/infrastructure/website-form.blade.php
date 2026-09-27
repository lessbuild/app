@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="$importing ? __('Import a website') : __('Create a website')" :description="$importing ? __('Adopt an application already in /var/www on an app server. Its files, Caddy site and database are left as they are.') : __('We set up the Caddy site, a MySQL database and user, and the .env file.')">
    @error('plan')<x-signal.ui.alert tone="warning" role="alert">{{ $message }}</x-signal.ui.alert>@enderror
    @if ($hosts->isEmpty())
        <x-signal.ui.empty-state icon="server" :title="__('No app servers ready')" :description="__('Websites need an active app server with MySQL. Create one first.')">
            <x-slot:action><x-signal.ui.button :href="route('infrastructure.servers.create', $project)" variant="secondary">{{ __('Create a server') }}</x-signal.ui.button></x-slot:action>
        </x-signal.ui.empty-state>
    @else
        <x-signal.ui.card>
            <form method="POST" action="{{ $importing ? route('infrastructure.websites.import', $project) : route('infrastructure.websites.store', $project) }}" class="grid items-start gap-5 p-4 sm:grid-cols-2 sm:p-6">
                @csrf
                @if ($importing)
                    <x-signal.ui.input-field name="name" :label="__('Name')" maxlength="255" required />
                    <x-signal.ui.select-field name="server_id" :label="__('Server')" required>
                        @foreach ($hosts as $host)
                            <option value="{{ $host->id }}" @selected((int) old('server_id') === $host->id)>{{ $host->label() }} · {{ $host->public_ip }}</option>
                        @endforeach
                    </x-signal.ui.select-field>
                    <x-signal.ui.input-field name="url" :label="__('Domain')" maxlength="255" placeholder="shop.example.com" required />
                    <x-signal.ui.input-field name="deployment_slug" :label="__('Directory in /var/www')" maxlength="32" placeholder="shop" :description="__('Lowercase letters, numbers and dashes.')" required />
                    <div class="sm:col-span-2"><x-signal.ui.textarea-field name="description" :label="__('Description')" rows="2" maxlength="2000" /></div>
                @else
                    @include('infrastructure._website-fields', ['website' => null])
                @endif
                <div class="flex flex-wrap gap-3 sm:col-span-2">
                    <x-signal.ui.button type="submit" variant="primary">{{ $importing ? __('Import website') : __('Create website') }}</x-signal.ui.button>
                    <x-signal.ui.button :href="route('infrastructure.websites', $project)" variant="quiet">{{ __('Cancel') }}</x-signal.ui.button>
                </div>
            </form>
        </x-signal.ui.card>
    @endif
</x-signal.layouts.project>
