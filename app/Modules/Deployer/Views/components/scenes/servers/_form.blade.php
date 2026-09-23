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

<div class="grid gap-6 bg-surface px-5 py-5 sm:grid-cols-2 sm:px-8" data-server-catalog>
    <div>
        <label for="{{ $inputId('provider_id') }}" class="ui-label">{{ __('Providers') }}</label>
        <select id="{{ $inputId('provider_id') }}" name="provider_id" class="ui-input" required>
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
        <p class="ui-help" data-server-catalog-status aria-live="polite"></p>
    </div>

    <div>
        <label for="{{ $inputId('type') }}" class="ui-label">{{ __('Server Type') }}</label>
        <select id="{{ $inputId('type') }}" name="type" class="ui-input" required>
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
        <label for="{{ $inputId('name') }}" class="ui-label">{{ __('Cloud server name') }}</label>
        <input
            value="{{ old('name') ?? ($server?->name ?? null) }}"
            type="text"
            name="name"
            id="{{ $inputId('name') }}"
            maxlength="255"
            required
            class="ui-input"
            placeholder="Example: Deployer"
        >
        <x-forms.errors name="name" />
    </div>

    <div>
        <label for="{{ $inputId('image') }}" class="ui-label">{{ __('Image') }}</label>
        <select id="{{ $inputId('image') }}" name="image" class="ui-input" required data-selected="{{ old('image', $server?->image ?? '') }}">
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
        <label for="{{ $inputId('region') }}" class="ui-label">{{ __('Region') }}</label>
        <select id="{{ $inputId('region') }}" name="region" class="ui-input" required data-selected="{{ old('region', $server?->region ?? '') }}">
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
        <label for="{{ $inputId('size') }}" class="ui-label">{{ __('Sizes') }}</label>
        <select id="{{ $inputId('size') }}" name="size" class="ui-input" required data-selected="{{ old('size', $server?->size ?? '') }}">
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
            <legend class="text-sm font-semibold text-ink">{{ __('Provisioning recipes') }}</legend>
            <p class="ui-help">{{ __('Selected recipes run as root, in this order, while the server is provisioned.') }}</p>
            <div class="ui-panel mt-3 grid gap-3 p-4 sm:grid-cols-2">
                @foreach ($recipes as $recipe)
                    <label class="ui-choice">
                        <input
                            class="ui-check mt-1"
                            type="checkbox"
                            name="recipes[]"
                            value="{{ $recipe->id }}"
                            @checked(in_array($recipe->id, old('recipes', [])))
                        >
                        <span>
                            <span class="block text-sm font-medium text-ink">{{ $recipe->name }}</span>
                            @if ($recipe->description)
                                <span class="mt-1 block text-xs text-muted">{{ $recipe->description }}</span>
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
