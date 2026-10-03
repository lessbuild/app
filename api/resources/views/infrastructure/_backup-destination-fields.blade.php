@php($destination ??= null)
@php($prefix = $destination ? "destination-{$destination->id}-" : 'destination-')
<x-signal.ui.input-field :id="$prefix.'name'" name="name" :label="__('Name')" :value="old('name', $destination?->name)" maxlength="120" required />
<x-signal.ui.select-field :id="$prefix.'storage'" name="storage_provider" :label="__('Storage')">
    @foreach ($presets as $key => $preset)
        <option value="{{ $key }}" @selected(old('storage_provider', $destination?->storage_provider ?? 'digitalocean_spaces') === $key)>{{ $preset['name'] }}</option>
    @endforeach
</x-signal.ui.select-field>
<x-signal.ui.input-field :id="$prefix.'region'" name="region" :label="__('Region')" :value="old('region', $destination?->region)" placeholder="ams3" maxlength="64" required />
<x-signal.ui.input-field :id="$prefix.'endpoint'" name="endpoint" :label="__('Endpoint')" :value="old('endpoint', $destination?->endpoint)" placeholder="https://ams3.digitaloceanspaces.com" :description="__('Filled in from the region for Spaces and Amazon S3.')" maxlength="255" />
<x-signal.ui.input-field :id="$prefix.'bucket'" name="bucket" :label="__('Bucket')" :value="old('bucket', $destination?->bucket)" maxlength="63" required />
<x-signal.ui.input-field :id="$prefix.'prefix'" name="path_prefix" :label="__('Path prefix')" :value="old('path_prefix', $destination?->path_prefix ?? 'buildpusher')" maxlength="120" required />
<x-signal.ui.input-field :id="$prefix.'access'" name="access_key" :label="__('Access key')" autocomplete="off" maxlength="1000" :placeholder="$destination ? __('Leave blank to keep') : ''" :required="! $destination" />
<x-signal.ui.input-field :id="$prefix.'secret'" name="secret_key" type="password" :restore="false" :label="__('Secret key')" autocomplete="new-password" maxlength="1000" :placeholder="$destination ? __('Leave blank to keep') : ''" :required="! $destination" />
