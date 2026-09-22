@props([
    'providers',
    'websites',
    'repository' => null,
    'fieldPrefix' => '',
])

<div class="space-y-6 bg-surface p-5 sm:p-6">

    <div>
        <label for="{{ $fieldPrefix }}website_id" class="ui-label">
            {{ __('Website') }}
        </label>
        <div class="mt-2">
            <select id="{{ $fieldPrefix }}website_id" name="website_id" class="ui-input w-full" required>
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

    <div>
        <label for="{{ $fieldPrefix }}provider_id" class="ui-label">
            {{ __('Provider') }}
        </label>
        <div class="mt-2">
            <select id="{{ $fieldPrefix }}provider_id" name="provider_id" class="ui-input w-full" required>
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

    <div>
        <label for="{{ $fieldPrefix }}name" class="ui-label">
            {{ __('Repository Name') }}
        </label>
        <div class="mt-2">
            <input
                value="{{ old('name', $repository->name ?? request()->query('name')) }}"
                type="text"
                name="name"
                id="{{ $fieldPrefix }}name"
                class="ui-input w-full"
                placeholder="Example: Deployer">
        </div>
        <x-forms.errors name="name"></x-forms.errors>
    </div>

    <div>
        <label for="{{ $fieldPrefix }}url" class="ui-label">
            {{ __('Repository URL') }}
        </label>
        <div class="mt-2 flex">
            <span class="inline-flex items-center rounded-l-lg border border-r-0 border-line bg-surface-muted px-3 text-sm text-muted">
                https://
            </span>
            <input
                value="{{ old('url', $repository->url ?? request()->query('url')) }}"
                type="text"
                name="url"
                id="{{ $fieldPrefix }}url"
                class="ui-input w-full rounded-l-none"
                placeholder="github.com, gitlab.com, or bitbucket.org">
        </div>
        <x-forms.errors name="url"></x-forms.errors>
    </div>

    <div>
        <label for="{{ $fieldPrefix }}branch" class="ui-label">
            {{ __('Deployment Branch') }}
        </label>
        <div class="mt-2">
            <input
                value="{{ old('branch', $repository->branch ?? request()->query('branch', 'main')) }}"
                type="text"
                name="branch"
                id="{{ $fieldPrefix }}branch"
                class="ui-input w-full"
                placeholder="main">
        </div>
        <x-forms.errors name="branch"></x-forms.errors>
    </div>

    <div>
        <label for="{{ $fieldPrefix }}deployment_root" class="ui-label">
            {{ __('Service root directory') }}
        </label>
        <div class="mt-2">
            <input
                value="{{ old('deployment_root', $repository->deployment_root ?? '') }}"
                type="text"
                name="deployment_root"
                id="{{ $fieldPrefix }}deployment_root"
                maxlength="512"
                autocomplete="off"
                class="ui-input w-full font-mono"
                placeholder="Repository root (.)"
            >
        </div>
        <p class="ui-help">
            {{ __('Optional path inside the checkout for this deployment target, such as apps/storefront. Leave blank for the repository root. Build, runtime, worker, Caddy, log and restore paths follow this directory.') }}
        </p>
        <x-forms.errors name="deployment_root"></x-forms.errors>
    </div>

    <fieldset class="ui-panel bg-surface-muted p-5">
        @php($autoDeployIncludePaths = old('auto_deploy_include_paths', $repository->auto_deploy_include_paths ?? []))
        @php($autoDeployExcludePaths = old('auto_deploy_exclude_paths', $repository->auto_deploy_exclude_paths ?? []))
        <legend class="ui-eyebrow">{{ __('Automatic deployment paths') }}</legend>
        <p class="ui-help">
            {{ __('Optional filters for authenticated push deployments. Use one path or glob per line, relative to the repository root. A blank include list considers every path; exclusions win. If a provider does not report changed paths, :app deploys conservatively.', ['app' => config('app.name')]) }}
        </p>
        <p class="ui-help">
            {{ __('Each repository record is one deployment target. Include shared dependency files explicitly for every target that depends on them.') }}
        </p>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
            <div>
                <label for="{{ $fieldPrefix }}auto_deploy_include_paths" class="ui-label">
                    {{ __('Include paths') }}
                </label>
                <textarea
                    id="{{ $fieldPrefix }}auto_deploy_include_paths"
                    name="auto_deploy_include_paths"
                    rows="5"
                    maxlength="5000"
                    autocomplete="off"
                    class="ui-input mt-2 min-h-[2.75rem] w-full font-mono"
                    placeholder="apps/storefront/**&#10;packages/shared/**">{{ is_array($autoDeployIncludePaths) ? collect($autoDeployIncludePaths)->map(fn (mixed $path): string => (string) $path)->implode("\n") : $autoDeployIncludePaths }}</textarea>
                <x-forms.errors name="auto_deploy_include_paths"></x-forms.errors>
                <x-forms.errors name="auto_deploy_include_paths.*"></x-forms.errors>
            </div>
            <div>
                <label for="{{ $fieldPrefix }}auto_deploy_exclude_paths" class="ui-label">
                    {{ __('Exclude paths') }}
                </label>
                <textarea
                    id="{{ $fieldPrefix }}auto_deploy_exclude_paths"
                    name="auto_deploy_exclude_paths"
                    rows="5"
                    maxlength="5000"
                    autocomplete="off"
                    class="ui-input mt-2 min-h-[2.75rem] w-full font-mono"
                    placeholder="docs/**&#10;*.md">{{ is_array($autoDeployExcludePaths) ? collect($autoDeployExcludePaths)->map(fn (mixed $path): string => (string) $path)->implode("\n") : $autoDeployExcludePaths }}</textarea>
                <x-forms.errors name="auto_deploy_exclude_paths"></x-forms.errors>
                <x-forms.errors name="auto_deploy_exclude_paths.*"></x-forms.errors>
            </div>
        </div>
    </fieldset>

    <div>
        <label for="{{ $fieldPrefix }}build_commands" class="ui-label">
            {{ __('Build commands') }}
        </label>
        <div class="mt-1">
            <textarea
                id="{{ $fieldPrefix }}build_commands"
                name="build_commands"
                rows="6"
                maxlength="10000"
                autocomplete="off"
                class="ui-input mt-2 w-full font-mono"
                placeholder="php artisan test&#10;npm run build">{{ old('build_commands', $repository->build_commands ?? '') }}</textarea>
        </div>
        <p class="ui-help">
            {{ __('Optional Bash commands run in the checked-out release after dependencies install and before activation.') }}
        </p>
        <x-forms.errors name="build_commands"></x-forms.errors>
    </div>

    <div>
        <label for="{{ $fieldPrefix }}post_deployment_commands" class="ui-label">
            {{ __('Post-deployment commands') }}
        </label>
        <div class="mt-1">
            <textarea
                id="{{ $fieldPrefix }}post_deployment_commands"
                name="post_deployment_commands"
                rows="6"
                maxlength="10000"
                autocomplete="off"
                class="ui-input mt-2 w-full font-mono"
                placeholder="php artisan queue:restart">{{ old('post_deployment_commands', $repository->post_deployment_commands ?? '') }}</textarea>
        </div>
        <p class="ui-help">
            {{ __('Optional Bash commands run in the active release before its health check. Hook failures restore the previous release symlink; database changes are not reversed.') }}
        </p>
        <p class="mt-1 text-sm text-muted">
            {{ __('Commands run with the deployment process privileges on your server. Do not place secrets directly in these fields.') }}
        </p>
        <x-forms.errors name="post_deployment_commands"></x-forms.errors>
    </div>

    <div>
        <label for="{{ $fieldPrefix }}description" class="ui-label">
            {{ __('Description') }}
        </label>
        <div class="mt-1">
            <textarea
                id="{{ $fieldPrefix }}description"
                name="description"
                rows="3"
                class="ui-input mt-2 w-full"
                placeholder="you@example.com">{{ old('description') ?? ($repository->description ?? null) }}</textarea>
        </div>
        <p class="ui-help">
            {{ __('Brief description of your repository') }}
        </p>
        <x-forms.errors name="description"></x-forms.errors>
    </div>
</div>
