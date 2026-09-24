@props([
    'formMarker' => null,
    'destinationId' => null,
    'cancelUrl' => null,
])

@php
    $isEdit = isset($destination) && $destination;
    $selectedProvider = old('storage_provider', $isEdit ? $destinationCatalog->forEndpoint($destination->endpoint) : \App\Modules\Deployer\Services\BackupDestinationCatalog::DIGITALOCEAN_SPACES);
    $selectedPreset = $destinationPresets[$selectedProvider] ?? $destinationPresets[\App\Modules\Deployer\Services\BackupDestinationCatalog::S3_COMPATIBLE];
@endphp

<form method="POST" action="{{ $action }}" class="mt-5 grid gap-4 sm:grid-cols-2">
    @csrf
    @if (filled($formMarker))
        <input type="hidden" name="_backup_destination_form" value="{{ $formMarker }}">
    @endif
    @if (filled($destinationId))
        <input type="hidden" name="_backup_destination_id" value="{{ $destinationId }}">
    @endif
    @if($isEdit)
        @method('PATCH')
    @endif
    <div class="sm:col-span-2">
        <x-signal.ui.select-field :id="$formId.'-storage_provider'" name="storage_provider" :label="__('Storage service')" :description="__('Choose a preset for provider-specific endpoint guidance. The choice is used for setup only and is not stored as a credential.')">
            @foreach($destinationPresets as $preset)
                <option value="{{ $preset->key }}" @selected($selectedProvider === $preset->key)>{{ $preset->name }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <p class="mt-1 text-sm text-ink">{{ $selectedPreset->description }}</p>
        <p class="ui-help">{{ __('Example endpoint: :endpoint · Region: :region', ['endpoint' => $selectedPreset->endpointHint, 'region' => $selectedPreset->regionHint]) }}</p>
    </div>
    <div>
        <x-signal.ui.input-field :id="$formId.'-name'" name="name" :label="__('Name')" :value="$isEdit ? $destination->name : ''" :placeholder="__('Offsite backups')" required />
    </div>
    <div>
        <x-signal.ui.input-field :id="$formId.'-region'" name="region" :label="__('Region')" :value="$isEdit ? $destination->region : ''" :placeholder="__('lon1')" :description="__('For Spaces, use the region shown by DigitalOcean, such as lon1 or nyc3.')" required />
    </div>
    <div class="sm:col-span-2">
        <x-signal.ui.input-field :id="$formId.'-endpoint'" name="endpoint" :label="__('S3 endpoint')" type="url" :value="$isEdit ? $destination->endpoint : ''" :placeholder="$selectedPreset->endpointHint" />
        @if(in_array($selectedProvider, [\App\Modules\Deployer\Services\BackupDestinationCatalog::DIGITALOCEAN_SPACES, \App\Modules\Deployer\Services\BackupDestinationCatalog::AMAZON_S3], true))
            <p class="ui-help">{{ __('Leave this blank and :app will derive the endpoint from the region. Do not paste a bucket URL or a control-plane API URL.', ['app' => config('app.name')]) }}</p>
        @else
            <p class="ui-help">{{ __('Use the S3 endpoint supplied by your storage provider. Do not paste a bucket URL or a control-plane API URL.') }}</p>
        @endif
    </div>
    <div>
        <x-signal.ui.input-field :id="$formId.'-bucket'" name="bucket" :label="__('Bucket name')" :value="$isEdit ? $destination->bucket : ''" :placeholder="__('buildpusher-backups')" required />
    </div>
    <div>
        <x-signal.ui.input-field :id="$formId.'-path_prefix'" name="path_prefix" :label="__('Folder prefix')" :value="$isEdit ? $destination->path_prefix : 'buildpusher'" :placeholder="__('buildpusher')" required />
    </div>
    <div>
        <x-signal.ui.input-field :id="$formId.'-access_key'" name="access_key" :label="__(':provider access key', ['provider' => $selectedPreset->name])" value="" :placeholder="$isEdit ? __('Leave blank to keep current key') : __('Access key')" autocomplete="off" :restore="false" :required="! $isEdit" />
    </div>
    <div>
        <x-signal.ui.input-field :id="$formId.'-secret_key'" name="secret_key" :label="__(':provider secret key', ['provider' => $selectedPreset->name])" type="password" value="" :placeholder="$isEdit ? __('Leave blank to keep current secret') : __('Secret key')" autocomplete="new-password" :restore="false" :required="! $isEdit" />
    </div>
    <x-signal.ui.card class="p-4 text-sm text-muted sm:col-span-2">
        <p class="font-bold text-ink">{{ __('Before you save') }}</p>
        <p class="mt-1">{{ __('Create a Spaces access key in DigitalOcean Spaces, not a regular DigitalOcean API token. After saving, verify this destination; :app writes, reads, and deletes a temporary object without needing an active website or server. The first real backup initializes the encrypted Restic repository.', ['app' => config('app.name')]) }}</p>
        @if($isEdit)
            <p class="mt-1">{{ __('Leave both credential fields blank to retain the encrypted values. Changing the bucket or folder is blocked when retained snapshots already use this destination.') }}</p>
        @endif
        @if($selectedPreset->documentationUrl)
            <a href="{{ $selectedPreset->documentationUrl }}" target="_blank" rel="noreferrer" class="ui-link mt-2 inline-block">{{ __('Open provider setup instructions') }}</a>
        @endif
    </x-signal.ui.card>
    <div class="sm:col-span-2">
        @if ($cancelUrl)
            <x-ui.button :href="$cancelUrl" variant="ghost" data-modal-cancel>{{ __('Cancel') }}</x-ui.button>
        @endif
        <x-ui.button type="submit" variant="primary">{{ $submitLabel }}</x-ui.button>
    </div>
</form>
