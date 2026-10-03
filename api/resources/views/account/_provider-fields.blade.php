{{-- Provider fields; $provider is null when connecting a new one. --}}
<x-signal.ui.input-field name="name" :label="__('Name')" :value="$provider?->name" maxlength="120" placeholder="Production cloud" required />
<x-signal.ui.select-field name="type" :label="__('Type')" required>
    @foreach ($types as $type)
        <option value="{{ $type->value }}" @selected(old('type', $provider?->type->value) === $type->value)>{{ $type->label() }} · {{ $type->purpose() }}</option>
    @endforeach
</x-signal.ui.select-field>
<div class="sm:col-span-2">
    <x-signal.ui.input-field name="base_url" type="url" :label="__('GitLab address (self-hosted only)')" :value="$provider?->base_url" placeholder="https://gitlab.example.com" :description="__('For GitLab providers on your own server. Leave empty for gitlab.com and for other types.')" autocomplete="off" />
</div>
<div class="sm:col-span-2">
    <x-signal.ui.input-field name="token" type="password" :label="$provider ? __('New API token') : __('API token')" :description="$provider ? __('Leave empty to keep the current token. It’s stored encrypted and never shown again.') : __('Stored encrypted and never shown again. Give it only the access servers or DNS need. For AWS Lightsail and EC2, enter ACCESS_KEY_ID:SECRET_ACCESS_KEY; for Google Compute Engine, paste a service account’s JSON key; for Azure, TENANT_ID:CLIENT_ID:SUBSCRIPTION_ID:CLIENT_SECRET; for OVHcloud, ENDPOINT:APPLICATION_KEY:APPLICATION_SECRET:CONSUMER_KEY:PROJECT_ID; for Scaleway, PROJECT_ID:SECRET_KEY; for UpCloud, an API user’s USERNAME:PASSWORD.')" autocomplete="off" :required="$provider === null" :restore="false" />
</div>
<div class="sm:col-span-2">
    <x-signal.ui.textarea-field name="description" :label="__('Description')" :value="$provider?->description" maxlength="1000" rows="2" />
</div>
