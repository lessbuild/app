<div class="px-4 py-5 bg-primary space-y-6 sm:p-6">

    <div class="col-span-3 sm:col-span-2">
        <label for="provider_id" class="block text-sm font-medium text-primary">
            {{ __('Providers') }}
        </label>
        <div class="mt-1 flex rounded-md shadow-sm">
            <select name="provider_id" class="input secondary rounded">
                @foreach($providers as $provider)
                    <option value="{{ $provider->id }}"
                        @selected(old('provider_id') == $provider->id || ($server->provider_id ?? null) == $provider->id)
                    >
                        {{ $provider->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <x-forms.errors name="provider_id"></x-forms.errors>
    </div>

    <div class="col-span-3 sm:col-span-2">
        <label for="type" class="block text-sm font-medium text-primary">
            {{ __('Server Type') }}
        </label>
        <div class="mt-1 flex rounded-md shadow-sm">
            <select name="type" class="input secondary rounded">
                @foreach($types as $type)
                    <option value="{{ $type->value }}"
                        @selected(old('type') == $type->value || ($server->type ?? null) == $type->value)
                    >
                        {{ ucwords($type->value) }} ({{ (implode(', ', $type->installs())) }})
                    </option>
                @endforeach
            </select>
        </div>
        <x-forms.errors name="type"></x-forms.errors>
    </div>

    <div class="col-span-3 sm:col-span-2">
        <label for="region" class="block text-sm font-medium text-primary">
            {{ __('Name') }}
        </label>
        <div class="mt-1 flex rounded-md shadow-sm">
            <input
                value="{{ old('name') ?? ($server->name ?? null) }}"
                type="text"
                name="name"
                id="name"
                class="input secondary rounded"
                placeholder="Example: Deployer">
        </div>
        <x-forms.errors name="name"></x-forms.errors>
    </div>

    <div class="col-span-3 sm:col-span-2">
        <label for="image" class="block text-sm font-medium text-primary">
            {{ __('Image') }}
        </label>
        <div class="mt-1 flex rounded-md shadow-sm">
            <select name="image" class="input secondary rounded">
                @foreach($images as $key => $value)
                    <option
                        value="{{ $key }}"
                        @selected(old('image') == $key || ($server->image ?? null) == $key)
                    >{{ $value }}</option>
                @endforeach
            </select>
        </div>
        <x-forms.errors name="image"></x-forms.errors>
    </div>

    <div class="col-span-3 sm:col-span-2">
        <label for="region" class="block text-sm font-medium text-primary">
            {{ __('Region') }}
        </label>
        <div class="mt-1 flex rounded-md shadow-sm">
            <select name="region" class="input secondary rounded">
                @foreach($regions as $region)
                    <option
                        value="{{ $region->slug }}"
                        @selected(old('region') == $region->slug || ($server->region ?? null) == $region->slug)
                    >{{ $region->name }}</option>
                @endforeach
            </select>
        </div>
        <x-forms.errors name="region"></x-forms.errors>
    </div>

    <div class="col-span-3 sm:col-span-2">
        <label for="size" class="block text-sm font-medium text-primary">
            {{ __('Sizes') }}
        </label>
        <div class="mt-1 flex rounded-md shadow-sm">
            <select name="size" class="input secondary rounded">
                @foreach($sizes as $size)
                    <option
                        value="{{ $size->slug }}"
                        @selected(old('size') == $size->slug || ($server->size ?? null) == $size->slug)
                    >
                        {{ __(':description :memory MB RAM - :cpu VCPU - :disk GB SSD $:price monthly', [
                            'description' => $size->description,
                            'memory' => $size->memory,
                            'cpu' => $size->vcpus,
                            'disk' => $size->disk,
                            'price' => $size->price_monthly
                        ]) }}
                    </option>
                @endforeach
            </select>
        </div>
        <x-forms.errors name="size"></x-forms.errors>
    </div>
</div>
