@props([
    'recipe' => null,
    'fieldPrefix' => '',
])

<div class="space-y-6 bg-surface px-4 py-5 sm:p-6">
    <div>
        <label for="{{ $fieldPrefix }}name" class="ui-label">{{ __('Name') }}</label>
        <input
            id="{{ $fieldPrefix }}name"
            class="ui-input mt-2"
            name="name"
            type="text"
            value="{{ old('name', $recipe?->name ?? '') }}"
            placeholder="Install monitoring agent"
            required
        >
        <x-forms.errors name="name" />
    </div>

    <div>
        <label for="{{ $fieldPrefix }}description" class="ui-label">{{ __('Description') }}</label>
        <textarea
            id="{{ $fieldPrefix }}description"
            class="ui-input mt-2"
            name="description"
            rows="3"
            placeholder="Describe what this recipe changes on a server."
        >{{ old('description', $recipe?->description ?? '') }}</textarea>
        <x-forms.errors name="description" />
    </div>

    <div>
        <label for="{{ $fieldPrefix }}script" class="ui-label">{{ __('Bash script') }}</label>
        <p class="mb-2 mt-1 text-xs text-muted">
            {{ __('This runs as root during provisioning. The recipe stops provisioning if any command fails.') }}
        </p>
        <textarea
            id="{{ $fieldPrefix }}script"
            class="ui-input font-mono"
            name="script"
            rows="14"
            spellcheck="false"
            placeholder="apt-get install -y fail2ban"
            required
        >{{ old('script', $recipe?->script ?? '') }}</textarea>
        <x-forms.errors name="script" />
    </div>

    <div class="ui-card bg-surface-muted p-4">
        <div class="flex items-start gap-3">
            <input type="hidden" name="is_published" value="0">
            <input
                id="{{ $fieldPrefix }}is_published"
                name="is_published"
                type="checkbox"
                value="1"
                class="ui-check mt-1"
                @checked(old('is_published', $recipe?->is_published ?? false))
            >
            <div>
                <label for="{{ $fieldPrefix }}is_published" class="block text-sm font-semibold text-ink">{{ __('Publish to the community gallery') }}</label>
                <p class="mt-1 text-xs text-muted">
                    {{ __('Everyone with an account can inspect and copy this script. Never publish passwords, tokens, private keys, or customer data.') }}
                </p>
            </div>
        </div>
        <div class="mt-4">
            <label for="{{ $fieldPrefix }}category" class="ui-label">{{ __('Gallery category') }}</label>
            <select id="{{ $fieldPrefix }}category" name="category" class="ui-input mt-2 w-full sm:max-w-xs">
                <option value="">{{ __('Select a category') }}</option>
                @foreach (\App\Models\Recipe::CATEGORIES as $category)
                    <option value="{{ $category }}" @selected(old('category', $recipe?->category ?? '') === $category)>
                        {{ str($category)->title() }}
                    </option>
                @endforeach
            </select>
            <x-forms.errors name="category" />
            <x-forms.errors name="is_published" />
        </div>
    </div>
</div>
