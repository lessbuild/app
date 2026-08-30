<div class="px-4 py-5 bg-primary space-y-6 sm:p-6">
    <div>
        <label for="name" class="block text-sm font-medium text-primary">{{ __('Name') }}</label>
        <input
            id="name"
            class="input secondary mt-1 rounded"
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
            class="input secondary mt-1 rounded"
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
            class="input secondary rounded font-mono"
            name="script"
            rows="14"
            spellcheck="false"
            placeholder="apt-get install -y fail2ban"
            required
        >{{ old('script', $recipe->script ?? '') }}</textarea>
        <x-forms.errors name="script" />
    </div>
</div>
