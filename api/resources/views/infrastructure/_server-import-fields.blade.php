{{-- The import form's fields: shared by the full page and the Import a server modal. --}}
<x-signal.ui.input-field id="import-server-name" name="name" :label="__('Name')" maxlength="31" placeholder="legacy-web" required />
<x-signal.ui.select-field id="import-server-type" name="type" :label="__('Type')" required>
    @foreach ($types as $type)
        <option value="{{ $type->value }}" @selected(old('type', 'app') === $type->value)>{{ $type->label() }}</option>
    @endforeach
</x-signal.ui.select-field>
<x-signal.ui.input-field id="import-server-ip" name="public_ip" :label="__('Public IP address')" placeholder="203.0.113.10" required />
<x-signal.ui.input-field id="import-server-port" name="ssh_port" type="number" :label="__('SSH port')" value="22" min="1" max="65535" required />
<div class="sm:col-span-2">
    <x-signal.ui.textarea-field id="import-server-key" name="ssh_private_key" :label="__('Root SSH private key')" rows="6" :restore="false" :description="__('An unencrypted key that logs in as root. It’s stored encrypted and used for provisioning and later operations.')" required />
</div>
<p class="text-sm text-muted sm:col-span-2">{{ __('Supported: Ubuntu :versions on x86-64 or ARM64. Provisioning may reconfigure or restart existing services.', ['versions' => implode(', ', config('infrastructure.supported_ubuntu_versions'))]) }}</p>
