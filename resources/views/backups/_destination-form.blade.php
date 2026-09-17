@php
    $isEdit = isset($destination) && $destination;
    $selectedProvider = old('storage_provider', $isEdit ? $destinationCatalog->forEndpoint($destination->endpoint) : \App\Services\BackupDestinationCatalog::DIGITALOCEAN_SPACES);
    $selectedPreset = $destinationPresets[$selectedProvider] ?? $destinationPresets[\App\Services\BackupDestinationCatalog::S3_COMPATIBLE];
@endphp

<form method="POST" action="{{ $action }}" class="mt-5 grid gap-4 sm:grid-cols-2">
    @csrf
    @if($isEdit)
        @method('PATCH')
    @endif
    <div class="sm:col-span-2">
        <label for="{{ $formId }}-storage_provider" class="block text-xs font-semibold uppercase text-secondary">{{ __('Storage service') }}</label>
        <select id="{{ $formId }}-storage_provider" name="storage_provider" class="input secondary mt-1 w-full rounded-md">
            @foreach($destinationPresets as $preset)
                <option value="{{ $preset->key }}" @selected($selectedProvider === $preset->key)>{{ $preset->name }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-secondary">{{ __('Choose a preset for provider-specific endpoint guidance. The choice is used for setup only and is not stored as a credential.') }}</p>
        <p class="mt-1 text-sm text-primary">{{ $selectedPreset->description }}</p>
        <p class="mt-1 text-xs text-secondary">{{ __('Example endpoint: :endpoint · Region: :region', ['endpoint' => $selectedPreset->endpointHint, 'region' => $selectedPreset->regionHint]) }}</p>
    </div>
    <div>
        <label for="{{ $formId }}-name" class="block text-xs font-semibold uppercase text-secondary">{{ __('Name') }}</label>
        <input id="{{ $formId }}-name" name="name" value="{{ old('name', $isEdit ? $destination->name : '') }}" placeholder="{{ __('Offsite backups') }}" class="input secondary mt-1 w-full rounded-md" required>
    </div>
    <div>
        <label for="{{ $formId }}-region" class="block text-xs font-semibold uppercase text-secondary">{{ __('Region') }}</label>
        <input id="{{ $formId }}-region" name="region" value="{{ old('region', $isEdit ? $destination->region : '') }}" placeholder="{{ __('lon1') }}" class="input secondary mt-1 w-full rounded-md" required>
        <p class="mt-1 text-xs text-secondary">{{ __('For Spaces, use the region shown by DigitalOcean, such as lon1 or nyc3.') }}</p>
    </div>
    <div class="sm:col-span-2">
        <label for="{{ $formId }}-endpoint" class="block text-xs font-semibold uppercase text-secondary">{{ __('S3 endpoint') }}</label>
        <input id="{{ $formId }}-endpoint" type="url" name="endpoint" value="{{ old('endpoint', $isEdit ? $destination->endpoint : '') }}" placeholder="{{ $selectedPreset->endpointHint }}" class="input secondary mt-1 w-full rounded-md">
        @if(in_array($selectedProvider, [\App\Services\BackupDestinationCatalog::DIGITALOCEAN_SPACES, \App\Services\BackupDestinationCatalog::AMAZON_S3], true))
            <p class="mt-1 text-xs text-secondary">{{ __('Leave this blank and BuildPusher will derive the endpoint from the region. Do not paste a bucket URL or a control-plane API URL.') }}</p>
        @else
            <p class="mt-1 text-xs text-secondary">{{ __('Use the S3 endpoint supplied by your storage provider. Do not paste a bucket URL or a control-plane API URL.') }}</p>
        @endif
    </div>
    <div>
        <label for="{{ $formId }}-bucket" class="block text-xs font-semibold uppercase text-secondary">{{ __('Bucket name') }}</label>
        <input id="{{ $formId }}-bucket" name="bucket" value="{{ old('bucket', $isEdit ? $destination->bucket : '') }}" placeholder="{{ __('buildpusher-backups') }}" class="input secondary mt-1 w-full rounded-md" required>
    </div>
    <div>
        <label for="{{ $formId }}-path_prefix" class="block text-xs font-semibold uppercase text-secondary">{{ __('Folder prefix') }}</label>
        <input id="{{ $formId }}-path_prefix" name="path_prefix" value="{{ old('path_prefix', $isEdit ? $destination->path_prefix : 'buildpusher') }}" placeholder="{{ __('buildpusher') }}" class="input secondary mt-1 w-full rounded-md" required>
    </div>
    <div>
        <label for="{{ $formId }}-access_key" class="block text-xs font-semibold uppercase text-secondary">{{ __(':provider access key', ['provider' => $selectedPreset->name]) }}</label>
        <input id="{{ $formId }}-access_key" name="access_key" value="" placeholder="{{ $isEdit ? __('Leave blank to keep current key') : __('Access key') }}" autocomplete="off" class="input secondary mt-1 w-full rounded-md" @required(!$isEdit)>
    </div>
    <div>
        <label for="{{ $formId }}-secret_key" class="block text-xs font-semibold uppercase text-secondary">{{ __(':provider secret key', ['provider' => $selectedPreset->name]) }}</label>
        <input id="{{ $formId }}-secret_key" type="password" name="secret_key" value="" placeholder="{{ $isEdit ? __('Leave blank to keep current secret') : __('Secret key') }}" autocomplete="new-password" class="input secondary mt-1 w-full rounded-md" @required(!$isEdit)>
    </div>
    <div class="ui-card ui-card--muted p-4 text-sm text-secondary sm:col-span-2">
        <p class="font-bold text-primary">{{ __('Before you save') }}</p>
        <p class="mt-1">{{ __('Create a Spaces access key in DigitalOcean Spaces, not a regular DigitalOcean API token. After saving, verify this destination; BuildPusher writes, reads, and deletes a temporary object without needing an active website or server. The first real backup initializes the encrypted Restic repository.') }}</p>
        @if($isEdit)
            <p class="mt-1">{{ __('Leave both credential fields blank to retain the encrypted values. Changing the bucket or folder is blocked when retained snapshots already use this destination.') }}</p>
        @endif
        @if($selectedPreset->documentationUrl)
            <a href="{{ $selectedPreset->documentationUrl }}" target="_blank" rel="noreferrer" class="mt-2 inline-block font-semibold text-ternary">{{ __('Open provider setup instructions') }}</a>
        @endif
    </div>
    <div class="sm:col-span-2">
        <x-ui.button type="submit" variant="primary">{{ $submitLabel }}</x-ui.button>
    </div>
</form>
