<div class="px-4 py-5 bg-primary space-y-6 sm:p-6" data-server-catalog>

    <div class="col-span-3 sm:col-span-2">
        <label for="provider_id" class="block text-sm font-medium text-primary">
            {{ __('Providers') }}
        </label>
        <div class="mt-1 flex rounded-md shadow-sm">
            <select id="provider_id" name="provider_id" class="input secondary rounded" required>
                @foreach($providers as $provider)
                    <option
                        value="{{ $provider->id }}"
                        data-catalog-url="{{ route('providers.server-catalog', $provider) }}"
                        @selected(old('provider_id') == $provider->id || ($server->provider_id ?? null) == $provider->id)
                    >
                        {{ $provider->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <x-forms.errors name="provider_id"></x-forms.errors>
        <p class="mt-2 text-xs text-secondary" data-server-catalog-status aria-live="polite"></p>
    </div>

    <div class="col-span-3 sm:col-span-2">
        <label for="type" class="block text-sm font-medium text-primary">
            {{ __('Server Type') }}
        </label>
        <div class="mt-1 flex rounded-md shadow-sm">
            <select id="type" name="type" class="input secondary rounded" required>
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
        <label for="name" class="block text-sm font-medium text-primary">
            {{ __('Cloud server name') }}
        </label>
        <div class="mt-1 flex rounded-md shadow-sm">
            <input
                value="{{ old('name') ?? ($server->name ?? null) }}"
                type="text"
                name="name"
                id="name"
                maxlength="255"
                required
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
            <select id="image" name="image" class="input secondary rounded" required data-selected="{{ old('image', $server->image ?? '') }}">
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
            <select id="region" name="region" class="input secondary rounded" required data-selected="{{ old('region', $server->region ?? '') }}">
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
            <select id="size" name="size" class="input secondary rounded" required data-selected="{{ old('size', $server->size ?? '') }}">
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

    @if ($recipes->isNotEmpty())
        <fieldset class="col-span-3 sm:col-span-2">
            <legend class="block text-sm font-medium text-primary">{{ __('Provisioning recipes') }}</legend>
            <p class="mt-1 text-xs text-secondary">{{ __('Selected recipes run as root, in this order, while the server is provisioned.') }}</p>
            <div class="mt-3 space-y-2 rounded border border-primary bg-secondary p-3">
                @foreach ($recipes as $recipe)
                    <label class="flex items-start gap-3">
                        <input
                            class="mt-1 rounded border-primary bg-primary text-ternary"
                            type="checkbox"
                            name="recipes[]"
                            value="{{ $recipe->id }}"
                            @checked(in_array($recipe->id, old('recipes', [])))
                        >
                        <span>
                            <span class="block text-sm font-medium text-primary">{{ $recipe->name }}</span>
                            @if ($recipe->description)
                                <span class="block text-xs text-secondary">{{ $recipe->description }}</span>
                            @endif
                        </span>
                    </label>
                @endforeach
            </div>
            <x-forms.errors name="recipes" />
            <x-forms.errors name="recipes.0" />
        </fieldset>
    @endif
</div>
