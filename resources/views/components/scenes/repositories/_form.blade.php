<div class="px-4 py-5 bg-primary space-y-6 sm:p-6">

    <div class="col-span-3 sm:col-span-2">
        <label for="website_id" class="block text-sm font-medium text-primary">
            {{ __('Website') }}
        </label>
        <div class="mt-1 flex rounded-md shadow-xs">
            <select id="website_id" name="website_id" class="input secondary rounded-sm" required>
                @foreach($websites as $website)
                    <option value="{{ $website->id }}"
                        @selected((string) old('website_id', $repository->website_id ?? request()->query('website_id', '')) === (string) $website->id)
                    >
                        {{ $website->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <x-forms.errors name="website_id"></x-forms.errors>
    </div>

    <div class="col-span-3 sm:col-span-2">
        <label for="provider_id" class="block text-sm font-medium text-primary">
            {{ __('Provider') }}
        </label>
        <div class="mt-1 flex rounded-md shadow-xs">
            <select id="provider_id" name="provider_id" class="input secondary rounded-sm" required>
                @foreach($providers as $provider)
                    <option
                        value="{{ $provider->id }}"
                        @selected(old('provider_id', request()->query('provider_id')) == $provider->id || ($repository->provider_id ?? null) == $provider->id)
                    >
                        {{ $provider->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <x-forms.errors name="provider_id"></x-forms.errors>
    </div>

    <div class="col-span-3 sm:col-span-2">
        <label for="name" class="block text-sm font-medium text-primary">
            {{ __('Repository Name') }}
        </label>
        <div class="mt-1 flex rounded-md shadow-xs">
            <input
                value="{{ old('name', $repository->name ?? request()->query('name')) }}"
                type="text"
                name="name"
                id="name"
                class="input secondary rounded-sm"
                placeholder="Example: Deployer">
        </div>
        <x-forms.errors name="name"></x-forms.errors>
    </div>

    <div class="col-span-3 sm:col-span-2">
        <label for="url" class="block text-sm font-medium text-primary">
            {{ __('Repository URL') }}
        </label>
        <div class="mt-1 flex rounded-md shadow-xs">
            <span class="inline-flex items-center px-3 rounded-l-md border border-r-0 border-primary bg-tertiary text-primary text-sm">
                https://
            </span>
            <input
                value="{{ old('url', $repository->url ?? request()->query('url')) }}"
                type="text"
                name="url"
                id="url"
                class="input secondary rounded-none rounded-r-md"
                placeholder="github.com, gitlab.com, or bitbucket.org">
        </div>
        <x-forms.errors name="url"></x-forms.errors>
    </div>

    <div class="col-span-3 sm:col-span-2">
        <label for="branch" class="block text-sm font-medium text-primary">
            {{ __('Deployment Branch') }}
        </label>
        <div class="mt-1 flex rounded-md shadow-xs">
            <input
                value="{{ old('branch', $repository->branch ?? request()->query('branch', 'main')) }}"
                type="text"
                name="branch"
                id="branch"
                class="input secondary rounded-sm"
                placeholder="main">
        </div>
        <x-forms.errors name="branch"></x-forms.errors>
    </div>

    <div>
        <label for="build_commands" class="block text-sm font-medium text-primary">
            {{ __('Build commands') }}
        </label>
        <div class="mt-1">
            <textarea
                id="build_commands"
                name="build_commands"
                rows="6"
                maxlength="10000"
                autocomplete="off"
                class="input secondary rounded-sm font-mono"
                placeholder="php artisan test&#10;npm run build">{{ old('build_commands', $repository->build_commands ?? '') }}</textarea>
        </div>
        <p class="mt-2 text-sm text-secondary">
            {{ __('Optional Bash commands run in the checked-out release after dependencies install and before activation.') }}
        </p>
        <x-forms.errors name="build_commands"></x-forms.errors>
    </div>

    <div>
        <label for="post_deployment_commands" class="block text-sm font-medium text-primary">
            {{ __('Post-deployment commands') }}
        </label>
        <div class="mt-1">
            <textarea
                id="post_deployment_commands"
                name="post_deployment_commands"
                rows="6"
                maxlength="10000"
                autocomplete="off"
                class="input secondary rounded-sm font-mono"
                placeholder="php artisan queue:restart">{{ old('post_deployment_commands', $repository->post_deployment_commands ?? '') }}</textarea>
        </div>
        <p class="mt-2 text-sm text-secondary">
            {{ __('Optional Bash commands run in the active release before its health check. Hook failures restore the previous release symlink; database changes are not reversed.') }}
        </p>
        <p class="mt-1 text-sm text-amber-700">
            {{ __('Commands run with the deployment process privileges on your server. Do not place secrets directly in these fields.') }}
        </p>
        <x-forms.errors name="post_deployment_commands"></x-forms.errors>
    </div>

    <div>
        <label for="description" class="block text-sm font-medium text-primary">
            {{ __('Description') }}
        </label>
        <div class="mt-1">
            <textarea
                id="description"
                name="description"
                rows="3"
                class="input secondary rounded-sm"
                placeholder="you@example.com">{{ old('description') ?? ($repository->description ?? null) }}</textarea>
        </div>
        <p class="mt-2 text-sm text-secondary">
            {{ __('Brief description of your repository') }}
        </p>
        <x-forms.errors name="description"></x-forms.errors>
    </div>
</div>
