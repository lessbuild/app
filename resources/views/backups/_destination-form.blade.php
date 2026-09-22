@props([
    'formMarker' => null,
    'destinationId' => null,
    'cancelUrl' => null,
])

@php
    $isEdit = isset($destination) && $destination;
    $selectedProvider = old('storage_provider', $isEdit ? $destinationCatalog->forEndpoint($destination->endpoint) : \App\Services\BackupDestinationCatalog::DIGITALOCEAN_SPACES);
    $selectedPreset = $destinationPresets[$selectedProvider] ?? $destinationPresets[\App\Services\BackupDestinationCatalog::S3_COMPATIBLE];
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
        <label for="{{ $formId }}-storage_provider" class="ui-label">{{ __('Storage service') }}</label>
        <select id="{{ $formId }}-storage_provider" name="storage_provider" class="ui-input">
            @foreach($destinationPresets as $preset)
                <option value="{{ $preset->key }}" @selected($selectedProvider === $preset->key)>{{ $preset->name }}</option>
            @endforeach
        </select>
        <x-forms.errors name="storage_provider" />
        <p class="ui-help">{{ __('Choose a preset for provider-specific endpoint guidance. The choice is used for setup only and is not stored as a credential.') }}</p>
        <p class="mt-1 text-sm text-ink">{{ $selectedPreset->description }}</p>
        <p class="ui-help">{{ __('Example endpoint: :endpoint · Region: :region', ['endpoint' => $selectedPreset->endpointHint, 'region' => $selectedPreset->regionHint]) }}</p>
    </div>
    <div>
        <label for="{{ $formId }}-name" class="ui-label">{{ __('Name') }}</label>
        <input id="{{ $formId }}-name" name="name" value="{{ old('name', $isEdit ? $destination->name : '') }}" placeholder="{{ __('Offsite backups') }}" class="ui-input" required>
        <x-forms.errors name="name" />
    </div>
    <div>
        <label for="{{ $formId }}-region" class="ui-label">{{ __('Region') }}</label>
        <input id="{{ $formId }}-region" name="region" value="{{ old('region', $isEdit ? $destination->region : '') }}" placeholder="{{ __('lon1') }}" class="ui-input" required>
        <x-forms.errors name="region" />
        <p class="ui-help">{{ __('For Spaces, use the region shown by DigitalOcean, such as lon1 or nyc3.') }}</p>
    </div>
    <div class="sm:col-span-2">
        <label for="{{ $formId }}-endpoint" class="ui-label">{{ __('S3 endpoint') }}</label>
        <input id="{{ $formId }}-endpoint" type="url" name="endpoint" value="{{ old('endpoint', $isEdit ? $destination->endpoint : '') }}" placeholder="{{ $selectedPreset->endpointHint }}" class="ui-input">
        <x-forms.errors name="endpoint" />
        @if(in_array($selectedProvider, [\App\Services\BackupDestinationCatalog::DIGITALOCEAN_SPACES, \App\Services\BackupDestinationCatalog::AMAZON_S3], true))
            <p class="ui-help">{{ __('Leave this blank and :app will derive the endpoint from the region. Do not paste a bucket URL or a control-plane API URL.', ['app' => config('app.name')]) }}</p>
        @else
            <p class="ui-help">{{ __('Use the S3 endpoint supplied by your storage provider. Do not paste a bucket URL or a control-plane API URL.') }}</p>
        @endif
    </div>
    <div>
        <label for="{{ $formId }}-bucket" class="ui-label">{{ __('Bucket name') }}</label>
        <input id="{{ $formId }}-bucket" name="bucket" value="{{ old('bucket', $isEdit ? $destination->bucket : '') }}" placeholder="{{ __('buildpusher-backups') }}" class="ui-input" required>
        <x-forms.errors name="bucket" />
    </div>
    <div>
        <label for="{{ $formId }}-path_prefix" class="ui-label">{{ __('Folder prefix') }}</label>
        <input id="{{ $formId }}-path_prefix" name="path_prefix" value="{{ old('path_prefix', $isEdit ? $destination->path_prefix : 'buildpusher') }}" placeholder="{{ __('buildpusher') }}" class="ui-input" required>
        <x-forms.errors name="path_prefix" />
    </div>
    <div>
        <label for="{{ $formId }}-access_key" class="ui-label">{{ __(':provider access key', ['provider' => $selectedPreset->name]) }}</label>
        <input id="{{ $formId }}-access_key" name="access_key" value="" placeholder="{{ $isEdit ? __('Leave blank to keep current key') : __('Access key') }}" autocomplete="off" class="ui-input" @required(!$isEdit)>
        <x-forms.errors name="access_key" />
    </div>
    <div>
        <label for="{{ $formId }}-secret_key" class="ui-label">{{ __(':provider secret key', ['provider' => $selectedPreset->name]) }}</label>
        <input id="{{ $formId }}-secret_key" type="password" name="secret_key" value="" placeholder="{{ $isEdit ? __('Leave blank to keep current secret') : __('Secret key') }}" autocomplete="new-password" class="ui-input" @required(!$isEdit)>
        <x-forms.errors name="secret_key" />
    </div>
    <div class="ui-panel p-4 text-sm text-muted sm:col-span-2">
        <p class="font-bold text-ink">{{ __('Before you save') }}</p>
        <p class="mt-1">{{ __('Create a Spaces access key in DigitalOcean Spaces, not a regular DigitalOcean API token. After saving, verify this destination; :app writes, reads, and deletes a temporary object without needing an active website or server. The first real backup initializes the encrypted Restic repository.', ['app' => config('app.name')]) }}</p>
        @if($isEdit)
            <p class="mt-1">{{ __('Leave both credential fields blank to retain the encrypted values. Changing the bucket or folder is blocked when retained snapshots already use this destination.') }}</p>
        @endif
        @if($selectedPreset->documentationUrl)
            <a href="{{ $selectedPreset->documentationUrl }}" target="_blank" rel="noreferrer" class="ui-link mt-2 inline-block">{{ __('Open provider setup instructions') }}</a>
        @endif
    </div>
    <div class="sm:col-span-2">
        @if ($cancelUrl)
            <x-ui.button :href="$cancelUrl" variant="ghost" data-modal-cancel>{{ __('Cancel') }}</x-ui.button>
        @endif
        <x-ui.button type="submit" variant="primary">{{ $submitLabel }}</x-ui.button>
    </div>
</form>
