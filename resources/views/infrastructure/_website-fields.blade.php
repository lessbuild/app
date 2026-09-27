{{-- Website fields; $website is null when creating. --}}
<x-signal.ui.input-field name="name" :label="__('Name')" :value="$website?->name" maxlength="255" placeholder="Shop" required />
<x-signal.ui.select-field name="server_id" :label="__('Server')" required>
    @foreach ($hosts as $host)
        <option value="{{ $host->id }}" @selected((int) old('server_id', $website?->server_id) === $host->id)>{{ $host->label() }} · {{ $host->public_ip }}</option>
    @endforeach
</x-signal.ui.select-field>
<x-signal.ui.input-field name="url" :label="__('Domain')" :value="$website?->url" maxlength="255" placeholder="shop.example.com" :description="__('Point its DNS at the server; Caddy gets the certificate.')" required />
<x-signal.ui.input-field name="release_retention" type="number" min="2" max="20" :label="__('Releases to keep')" :value="$website?->release_retention ?? 5" />
<div class="sm:col-span-2">
    <x-signal.ui.textarea-field name="description" :label="__('Description')" :value="$website?->description" rows="2" maxlength="2000" />
</div>
<div class="sm:col-span-2">
    <x-signal.ui.textarea-field name="env_file" :label="__('.env file')" :value="$website?->env_file" rows="6" class="font-mono" :restore="$website === null" :description="__('Stored encrypted and written to the server. Changing it sets the website up again.')" />
</div>
