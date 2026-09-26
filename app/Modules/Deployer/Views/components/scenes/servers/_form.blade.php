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

@php($inputId = fn (string $field): string => $fieldPrefix.$field)

<div class="grid gap-6 bg-surface px-5 py-5 sm:grid-cols-2 sm:px-8" data-server-catalog>
    <div class="grid min-w-0 gap-2">
        <x-signal.ui.select-field
            :id="$inputId('provider_id')"
            name="provider_id"
            :label="__('Providers')"
            required
            class="w-full"
        >
            @foreach ($providers as $provider)
                <option
                    value="{{ $provider->id }}"
                    data-catalog-url="{{ route('providers.server-catalog', $provider) }}"
                    @selected(old('provider_id') == $provider->id || ($server?->provider_id ?? null) == $provider->id)
                >
                    {{ $provider->name }}
                </option>
            @endforeach
        </x-signal.ui.select-field>
        <p class="ui-help" data-server-catalog-status aria-live="polite"></p>
    </div>

    <x-signal.ui.select-field
        :id="$inputId('type')"
        name="type"
        :label="__('Server Type')"
        required
        class="w-full"
    >
        @foreach ($types as $type)
            <option value="{{ $type->value }}" @selected(old('type') == $type->value || ($server?->type ?? null) == $type->value)>
                {{ ucwords($type->value) }} ({{ implode(', ', $type->installs()) }})
            </option>
        @endforeach
    </x-signal.ui.select-field>

    <x-signal.ui.input-field
        :id="$inputId('name')"
        name="name"
        :label="__('Cloud server name')"
        :value="$server?->name"
        maxlength="255"
        required
        placeholder="Example: Deployer"
        class="w-full"
    />

    <x-signal.ui.select-field
        :id="$inputId('image')"
        name="image"
        :label="__('Image')"
        :data-selected="old('image', $server?->image ?? '')"
        required
        class="w-full"
    >
        @foreach ($images as $key => $value)
            <option value="{{ $key }}" @selected(old('image') == $key || ($server?->image ?? null) == $key)>
                {{ $value }}
            </option>
        @endforeach
    </x-signal.ui.select-field>

    <x-signal.ui.select-field
        :id="$inputId('region')"
        name="region"
        :label="__('Region')"
        :data-selected="old('region', $server?->region ?? '')"
        required
        class="w-full"
    >
        @foreach ($regions as $region)
            <option value="{{ $region->slug }}" @selected(old('region') == $region->slug || ($server?->region ?? null) == $region->slug)>
                {{ $region->name }}
            </option>
        @endforeach
    </x-signal.ui.select-field>

    <x-signal.ui.select-field
        :id="$inputId('size')"
        name="size"
        :label="__('Sizes')"
        :data-selected="old('size', $server?->size ?? '')"
        required
        class="w-full"
    >
        @foreach ($sizes as $size)
            <option value="{{ $size->slug }}" @selected(old('size') == $size->slug || ($server?->size ?? null) == $size->slug)>
                {{ __(':description :memory MB RAM - :cpu VCPU - :disk GB SSD $:price monthly', [
                    'description' => $size->description,
                    'memory' => $size->memory,
                    'cpu' => $size->vcpus,
                    'disk' => $size->disk,
                    'price' => $size->price_monthly,
                ]) }}
            </option>
        @endforeach
    </x-signal.ui.select-field>

    @if ($recipes->isNotEmpty())
        @php($recipeErrorIds = collect([
            $errors->has('recipes') ? $inputId('recipes-error') : null,
            $errors->has('recipes.0') ? $inputId('recipes-index-error') : null,
        ])->filter()->implode(' '))

        <x-signal.ui.card
            as="fieldset"
            class="sm:col-span-2 p-5"
            :shadow="false"
            :aria-invalid="$recipeErrorIds !== '' ? 'true' : 'false'"
            :aria-describedby="$recipeErrorIds !== '' ? $recipeErrorIds : null"
        >
            <legend class="ui-eyebrow">{{ __('Provisioning recipes') }}</legend>
            <p class="ui-help">{{ __('Selected recipes run as root, in this order, while the server is provisioned.') }}</p>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                @foreach ($recipes as $recipe)
                    <x-signal.ui.choice
                        :id="$inputId('recipe-'.$recipe->id)"
                        name="recipes[]"
                        :value="$recipe->id"
                        :label="$recipe->name"
                        :checked="in_array($recipe->id, old('recipes', []))"
                        :restore="false"
                        :card="true"
                        :description="$recipe->description"
                        :error-key="false"
                        :show-errors="false"
                    />
                @endforeach
            </div>
        </x-signal.ui.card>
        @if ($recipeErrorIds !== '')
            <div class="sm:col-span-2">
                <x-forms.errors name="recipes" :id="$inputId('recipes-error')" />
                <x-forms.errors name="recipes.0" :id="$inputId('recipes-index-error')" />
            </div>
        @endif
    @endif
</div>
