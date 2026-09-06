<div class="px-4 py-5 bg-primary space-y-6 sm:p-6">
    <div>
        <label for="name" class="block text-sm font-medium text-primary">{{ __('Name') }}</label>
        <input
            id="name"
            class="input secondary mt-1 rounded-sm"
            name="name"
            type="text"
            value="{{ old('name', $recipe->name ?? '') }}"
            placeholder="Install monitoring agent"
            required
        >
        <x-forms.errors name="name" />
    </div>

    <div>
        <label for="description" class="block text-sm font-medium text-primary">{{ __('Description') }}</label>
        <textarea
            id="description"
            class="input secondary mt-1 rounded-sm"
            name="description"
            rows="3"
            placeholder="Describe what this recipe changes on a server."
        >{{ old('description', $recipe->description ?? '') }}</textarea>
        <x-forms.errors name="description" />
    </div>

    <div>
        <label for="script" class="block text-sm font-medium text-primary">{{ __('Bash script') }}</label>
        <p class="mb-2 mt-1 text-xs text-secondary">
            {{ __('This runs as root during provisioning. The recipe stops provisioning if any command fails.') }}
        </p>
        <textarea
            id="script"
            class="input secondary rounded-sm font-mono"
            name="script"
            rows="14"
            spellcheck="false"
            placeholder="apt-get install -y fail2ban"
            required
        >{{ old('script', $recipe->script ?? '') }}</textarea>
        <x-forms.errors name="script" />
    </div>

    <div class="rounded-lg border border-primary bg-secondary p-4">
        <div class="flex items-start gap-3">
            <input type="hidden" name="is_published" value="0">
            <input
                id="is_published"
                name="is_published"
                type="checkbox"
                value="1"
                class="mt-1 rounded-sm"
                @checked(old('is_published', $recipe->is_published ?? false))
            >
            <div>
                <label for="is_published" class="block text-sm font-medium text-primary">{{ __('Publish to the community gallery') }}</label>
                <p class="mt-1 text-xs text-secondary">
                    {{ __('Everyone with an account can inspect and copy this script. Never publish passwords, tokens, private keys, or customer data.') }}
                </p>
            </div>
        </div>
        <div class="mt-4">
            <label for="category" class="block text-sm font-medium text-primary">{{ __('Gallery category') }}</label>
            <select id="category" name="category" class="input secondary mt-1 w-full rounded-sm sm:max-w-xs">
                <option value="">{{ __('Select a category') }}</option>
                @foreach (\App\Models\Recipe::CATEGORIES as $category)
                    <option value="{{ $category }}" @selected(old('category', $recipe->category ?? '') === $category)>
                        {{ str($category)->title() }}
                    </option>
                @endforeach
            </select>
            <x-forms.errors name="category" />
            <x-forms.errors name="is_published" />
        </div>
    </div>
</div>
