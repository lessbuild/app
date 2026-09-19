@props([
    'types',
    'providers',
    'sizes',
    'images',
    'regions',
    'recipes',
    'server' => null,
    'fieldPrefix' => '',
])

@php
    $inputId = fn (string $field): string => $fieldPrefix.$field;
@endphp

<div class="grid gap-6 bg-primary px-5 py-5 sm:grid-cols-2 sm:px-8" data-server-catalog>
    <div>
        <label for="{{ $inputId('provider_id') }}" class="block text-sm font-semibold text-primary">
            {{ __('Providers') }}
        </label>
        <select id="{{ $inputId('provider_id') }}" name="provider_id" class="input secondary mt-2 w-full rounded-lg" required>
            @foreach ($providers as $provider)
                <option
                    value="{{ $provider->id }}"
                    data-catalog-url="{{ route('providers.server-catalog', $provider) }}"
                    @selected(old('provider_id') == $provider->id || ($server?->provider_id ?? null) == $provider->id)
                >
                    {{ $provider->name }}
                </option>
            @endforeach
        </select>
        <x-forms.errors name="provider_id" />
        <p class="mt-2 text-xs text-secondary" data-server-catalog-status aria-live="polite"></p>
    </div>

    <div>
        <label for="{{ $inputId('type') }}" class="block text-sm font-semibold text-primary">
            {{ __('Server Type') }}
        </label>
        <select id="{{ $inputId('type') }}" name="type" class="input secondary mt-2 w-full rounded-lg" required>
            @foreach ($types as $type)
                <option
                    value="{{ $type->value }}"
                    @selected(old('type') == $type->value || ($server?->type ?? null) == $type->value)
                >
                    {{ ucwords($type->value) }} ({{ implode(', ', $type->installs()) }})
                </option>
            @endforeach
        </select>
        <x-forms.errors name="type" />
    </div>

    <div>
        <label for="{{ $inputId('name') }}" class="block text-sm font-semibold text-primary">
            {{ __('Cloud server name') }}
        </label>
        <input
            value="{{ old('name') ?? ($server?->name ?? null) }}"
            type="text"
            name="name"
            id="{{ $inputId('name') }}"
            maxlength="255"
            required
            class="input secondary mt-2 w-full rounded-lg"
            placeholder="Example: Deployer"
        >
        <x-forms.errors name="name" />
    </div>

    <div>
        <label for="{{ $inputId('image') }}" class="block text-sm font-semibold text-primary">
            {{ __('Image') }}
        </label>
        <select id="{{ $inputId('image') }}" name="image" class="input secondary mt-2 w-full rounded-lg" required data-selected="{{ old('image', $server?->image ?? '') }}">
            @foreach ($images as $key => $value)
                <option
                    value="{{ $key }}"
                    @selected(old('image') == $key || ($server?->image ?? null) == $key)
                >{{ $value }}</option>
            @endforeach
        </select>
        <x-forms.errors name="image" />
    </div>

    <div>
        <label for="{{ $inputId('region') }}" class="block text-sm font-semibold text-primary">
            {{ __('Region') }}
        </label>
        <select id="{{ $inputId('region') }}" name="region" class="input secondary mt-2 w-full rounded-lg" required data-selected="{{ old('region', $server?->region ?? '') }}">
            @foreach ($regions as $region)
                <option
                    value="{{ $region->slug }}"
                    @selected(old('region') == $region->slug || ($server?->region ?? null) == $region->slug)
                >{{ $region->name }}</option>
            @endforeach
        </select>
        <x-forms.errors name="region" />
    </div>

    <div>
        <label for="{{ $inputId('size') }}" class="block text-sm font-semibold text-primary">
            {{ __('Sizes') }}
        </label>
        <select id="{{ $inputId('size') }}" name="size" class="input secondary mt-2 w-full rounded-lg" required data-selected="{{ old('size', $server?->size ?? '') }}">
            @foreach ($sizes as $size)
                <option
                    value="{{ $size->slug }}"
                    @selected(old('size') == $size->slug || ($server->size ?? null) == $size->slug)
                >
                    {{ __(':description :memory MB RAM - :cpu VCPU - :disk GB SSD $:price monthly', [
                        'description' => $size->description,
                        'memory' => $size->memory,
                        'cpu' => $size->vcpus,
                        'disk' => $size->disk,
                        'price' => $size->price_monthly,
                    ]) }}
                </option>
            @endforeach
        </select>
        <x-forms.errors name="size" />
    </div>

    @if ($recipes->isNotEmpty())
        <fieldset class="sm:col-span-2">
            <legend class="text-sm font-semibold text-primary">{{ __('Provisioning recipes') }}</legend>
            <p class="mt-1 text-xs text-secondary">{{ __('Selected recipes run as root, in this order, while the server is provisioned.') }}</p>
            <div class="mt-3 grid gap-3 rounded-xl border border-primary bg-secondary p-4 sm:grid-cols-2">
                @foreach ($recipes as $recipe)
                    <label class="flex items-start gap-3 rounded-lg p-2 hover:bg-primary">
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
                                <span class="mt-1 block text-xs text-secondary">{{ $recipe->description }}</span>
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
