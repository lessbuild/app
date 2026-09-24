@props([
    'recipe' => null,
    'fieldPrefix' => '',
])

<div class="space-y-6 bg-surface px-4 py-5 sm:p-6">
    <x-signal.ui.input-field
        :id="$fieldPrefix.'name'"
        name="name"
        :label="__('Name')"
        :value="$recipe?->name"
        placeholder="Install monitoring agent"
        required
        class="w-full"
    />

    <x-signal.ui.textarea-field
        :id="$fieldPrefix.'description'"
        name="description"
        :label="__('Description')"
        :value="$recipe?->description"
        rows="3"
        placeholder="Describe what this recipe changes on a server."
        class="w-full"
    />

    <x-signal.ui.textarea-field
        :id="$fieldPrefix.'script'"
        name="script"
        :label="__('Bash script')"
        :value="$recipe?->script"
        :description="__('This runs as root during provisioning. The recipe stops provisioning if any command fails.')"
        rows="14"
        spellcheck="false"
        placeholder="apt-get install -y fail2ban"
        required
        class="w-full font-mono"
    />

    <x-signal.ui.card tone="muted" class="space-y-4 p-4" :shadow="false">
        <x-signal.ui.checkbox
            :id="$fieldPrefix.'is_published'"
            name="is_published"
            value="1"
            :checked="$recipe?->is_published ?? false"
            unchecked-value="0"
            :description="__('Everyone with an account can inspect and copy this script. Never publish passwords, tokens, private keys, or customer data.')"
        >
            {{ __('Publish to the community gallery') }}
        </x-signal.ui.checkbox>

        <x-signal.ui.select-field
            :id="$fieldPrefix.'category'"
            name="category"
            :label="__('Gallery category')"
            class="w-full sm:max-w-xs"
        >
            <option value="">{{ __('Select a category') }}</option>
            @foreach (\App\Modules\Deployer\Models\Recipe::CATEGORIES as $category)
                <option value="{{ $category }}" @selected(old('category', $recipe?->category ?? '') === $category)>
                    {{ str($category)->title() }}
                </option>
            @endforeach
        </x-signal.ui.select-field>
    </x-signal.ui.card>
</div>
