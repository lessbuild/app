{{-- The new server form, shared by the Servers page's "Create a server" modal (loaded when it opens, or rendered
     in place after a failed submit) and the stand-alone page. Switching provider reloads it with that provider's
     regions, sizes and images. --}}
@error('plan')<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror
@if ($providers->isEmpty())
    <x-signal.ui.empty-state icon="server" :title="__('Connect a cloud provider first')" :description="__('Add a DigitalOcean, Hetzner Cloud, Vultr, Linode or AWS Lightsail token on the account’s Providers page.')">
        <x-slot:action><x-signal.ui.button :href="route('account.providers')" variant="secondary">{{ __('Providers') }}</x-signal.ui.button></x-slot:action>
    </x-signal.ui.empty-state>
@else
    <form method="GET" action="{{ route('infrastructure.servers.create', $project) }}" class="flex flex-wrap items-end gap-3" data-fragment-form>
        <x-signal.ui.select-field name="provider" :label="__('Provider')" data-fragment-autosubmit>
            @foreach ($providers as $option)
                <option value="{{ $option->id }}" @selected($provider?->id === $option->id)>{{ $option->name }} · {{ $option->type->label() }}</option>
            @endforeach
        </x-signal.ui.select-field>
        {{-- In the modal, choosing a provider reloads the form; the button is for the stand-alone page. --}}
        <x-signal.ui.button type="submit" variant="secondary" :class="$inModal ? 'sr-only' : null">{{ __('Load regions and sizes') }}</x-signal.ui.button>
    </form>

    @if ($catalogError)
        <x-signal.ui.alert tone="danger" role="alert">{{ $catalogError }}</x-signal.ui.alert>
    @elseif ($catalog !== null)
        <x-signal.ui.card :class="$inModal ? 'border-0 shadow-none' : null">
            <form method="POST" action="{{ route('infrastructure.servers.store', $project) }}" @class(['grid items-start gap-5 sm:grid-cols-2', 'p-4 sm:p-6' => ! $inModal, 'pt-2' => $inModal])>
                @csrf
                @if ($inModal)<input type="hidden" name="_modal" value="create-server">@endif
                <input type="hidden" name="provider_id" value="{{ $provider->id }}">
                <x-signal.ui.input-field name="name" :label="__('Name')" maxlength="31" placeholder="web-1" :description="__('Letters, numbers and dashes. Used as the hostname.')" required />
                <x-signal.ui.select-field name="type" :label="__('Type')" required>
                    @foreach ($types as $type)
                        <option value="{{ $type->value }}" @selected(old('type', 'app') === $type->value)>{{ $type->label() }} · {{ implode(', ', $type->installs()) }}</option>
                    @endforeach
                </x-signal.ui.select-field>
                <x-signal.ui.select-field name="database_engine" :label="__('Database engine')" :description="__('For database servers.')">
                    <option value="mysql" @selected(old('database_engine', 'mysql') === 'mysql')>MySQL</option>
                    <option value="postgres" @selected(old('database_engine') === 'postgres')>PostgreSQL</option>
                </x-signal.ui.select-field>
                @foreach (['region' => __('Region'), 'size' => __('Size'), 'image' => __('Ubuntu image')] as $field => $label)
                    <x-signal.ui.select-field :name="$field" :label="$label" required>
                        @foreach ($catalog[$field === 'region' ? 'regions' : ($field === 'size' ? 'sizes' : 'images')] as $choice)
                            <option value="{{ $choice['id'] }}" @selected(old($field) === $choice['id'])>{{ $choice['label'] }}</option>
                        @endforeach
                    </x-signal.ui.select-field>
                @endforeach
                @if ($recipes->isNotEmpty())
                    <fieldset class="grid gap-2 sm:col-span-2">
                        <legend class="mb-1 text-sm font-bold text-ink">{{ __('Recipes') }}</legend>
                        <p class="text-xs text-muted">{{ __('Scripts that run as root at the end of provisioning, in this order. The server keeps the version it ran.') }}</p>
                        @foreach ($recipes as $recipe)
                            <x-signal.ui.checkbox :id="'recipe-'.$recipe->id" name="recipe_ids[]" :value="$recipe->id" :checked="in_array((string) $recipe->id, (array) old('recipe_ids', []), true)" :restore="false">{{ $recipe->name }}@if ($recipe->description) <span class="text-xs text-muted">· {{ \Illuminate\Support\Str::limit($recipe->description, 80) }}</span>@endif</x-signal.ui.checkbox>
                        @endforeach
                    </fieldset>
                @endif
                <p class="text-sm text-muted sm:col-span-2">{{ __('The provider bills you for the server. A new SSH key is made for it; the root and MySQL passwords are shown once, after creation.') }}</p>
                <div class="flex flex-wrap gap-3 sm:col-span-2">
                    <x-signal.ui.button type="submit" variant="primary">{{ __('Create server') }}</x-signal.ui.button>
                    @unless ($inModal)<x-signal.ui.button :href="route('infrastructure.servers', $project)" variant="quiet">{{ __('Cancel') }}</x-signal.ui.button>@endunless
                </div>
            </form>
        </x-signal.ui.card>
    @endif
@endif
