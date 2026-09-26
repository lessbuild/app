@props([
    'providers',
    'websites',
    'repository' => null,
    'fieldPrefix' => '',
])

@php($autoDeployIncludePaths = old('auto_deploy_include_paths', $repository?->auto_deploy_include_paths ?? []))
@php($autoDeployExcludePaths = old('auto_deploy_exclude_paths', $repository?->auto_deploy_exclude_paths ?? []))
@php($autoDeployIncludePathsValue = is_array($autoDeployIncludePaths) ? collect($autoDeployIncludePaths)->map(fn (mixed $path): string => (string) $path)->implode("\n") : $autoDeployIncludePaths)
@php($autoDeployExcludePathsValue = is_array($autoDeployExcludePaths) ? collect($autoDeployExcludePaths)->map(fn (mixed $path): string => (string) $path)->implode("\n") : $autoDeployExcludePaths)

<div class="space-y-6 bg-surface p-5 sm:p-6">
    <x-signal.ui.select-field
        :id="$fieldPrefix.'website_id'"
        name="website_id"
        :label="__('Website')"
        required
        class="w-full"
    >
        @foreach ($websites as $website)
            <option value="{{ $website->id }}" @selected((string) old('website_id', $repository?->website_id ?? request()->query('website_id', '')) === (string) $website->id)>
                {{ $website->name }}
            </option>
        @endforeach
    </x-signal.ui.select-field>

    <x-signal.ui.select-field
        :id="$fieldPrefix.'provider_id'"
        name="provider_id"
        :label="__('Provider')"
        required
        class="w-full"
    >
        @foreach ($providers as $provider)
            <option value="{{ $provider->id }}" @selected(old('provider_id', request()->query('provider_id')) == $provider->id || ($repository?->provider_id ?? null) == $provider->id)>
                {{ $provider->name }}
            </option>
        @endforeach
    </x-signal.ui.select-field>

    <x-signal.ui.input-field
        :id="$fieldPrefix.'name'"
        name="name"
        :label="__('Repository Name')"
        :value="$repository?->name ?? request()->query('name')"
        placeholder="Example: Deployer"
        class="w-full"
    />

    <x-signal.ui.input-field
        :id="$fieldPrefix.'url'"
        name="url"
        :label="__('Repository URL')"
        :value="$repository?->url ?? request()->query('url')"
        placeholder="github.com, gitlab.com, or bitbucket.org"
    >
        <x-slot:prefix>
            <x-signal.ui.input-addon>https://</x-signal.ui.input-addon>
        </x-slot:prefix>
    </x-signal.ui.input-field>

    <x-signal.ui.input-field
        :id="$fieldPrefix.'branch'"
        name="branch"
        :label="__('Deployment Branch')"
        :value="$repository?->branch ?? request()->query('branch', 'main')"
        placeholder="main"
        class="w-full"
    />

    <x-signal.ui.input-field
        :id="$fieldPrefix.'deployment_root'"
        name="deployment_root"
        :label="__('Service root directory')"
        :value="$repository?->deployment_root"
        maxlength="512"
        autocomplete="off"
        placeholder="Repository root (.)"
        class="w-full font-mono"
        :description="__('Optional path inside the checkout for this deployment target, such as apps/storefront. Leave blank for the repository root. Build, runtime, worker, Caddy, log and restore paths follow this directory.')"
    />

    <x-signal.ui.card as="fieldset" class="bg-surface-muted p-5" :shadow="false">
        <legend class="ui-eyebrow">{{ __('Automatic deployment paths') }}</legend>
        <p class="ui-help">
            {{ __('Optional filters for authenticated push deployments. Use one path or glob per line, relative to the repository root. A blank include list considers every path; exclusions win. If a provider does not report changed paths, :app deploys conservatively.', ['app' => config('app.name')]) }}
        </p>
        <p class="ui-help">
            {{ __('Each repository record is one deployment target. Include shared dependency files explicitly for every target that depends on them.') }}
        </p>

        <div class="mt-4 grid gap-4 md:grid-cols-2">
            <x-signal.ui.textarea-field
                :id="$fieldPrefix.'auto_deploy_include_paths'"
                name="auto_deploy_include_paths"
                error-key="auto_deploy_include_paths.*"
                :label="__('Include paths')"
                :value="$autoDeployIncludePathsValue"
                rows="5"
                maxlength="5000"
                autocomplete="off"
                class="min-h-[2.75rem] w-full font-mono"
                placeholder="apps/storefront/**&#10;packages/shared/**"
                :description="__('Use one repository-relative path or glob per line. Leave empty to consider every path.')"
            />

            <x-signal.ui.textarea-field
                :id="$fieldPrefix.'auto_deploy_exclude_paths'"
                name="auto_deploy_exclude_paths"
                error-key="auto_deploy_exclude_paths.*"
                :label="__('Exclude paths')"
                :value="$autoDeployExcludePathsValue"
                rows="5"
                maxlength="5000"
                autocomplete="off"
                class="min-h-[2.75rem] w-full font-mono"
                placeholder="docs/**&#10;*.md"
                :description="__('Use one repository-relative path or glob per line. Exclusions take precedence over included paths.')"
            />
        </div>
    </x-signal.ui.card>

    <x-signal.ui.textarea-field
        :id="$fieldPrefix.'build_commands'"
        name="build_commands"
        :label="__('Build commands')"
        :value="$repository?->build_commands"
        rows="6"
        maxlength="10000"
        autocomplete="off"
        class="w-full font-mono"
        placeholder="php artisan test&#10;npm run build"
        :description="__('Optional Bash commands run in the checked-out release after dependencies install and before activation.')"
    />

    <x-signal.ui.textarea-field
        :id="$fieldPrefix.'post_deployment_commands'"
        name="post_deployment_commands"
        :label="__('Post-deployment commands')"
        :value="$repository?->post_deployment_commands"
        rows="6"
        maxlength="10000"
        autocomplete="off"
        class="w-full font-mono"
        placeholder="php artisan queue:restart"
        :description="__('Optional Bash commands run in the active release before its health check. Hook failures restore the previous release symlink; database changes are not reversed.')"
    >
        <p class="mt-1 text-sm text-muted">
            {{ __('Commands run with the deployment process privileges on your server. Do not place secrets directly in these fields.') }}
        </p>
    </x-signal.ui.textarea-field>

    <x-signal.ui.textarea-field
        :id="$fieldPrefix.'description'"
        name="description"
        :label="__('Description')"
        :value="$repository?->description"
        rows="3"
        class="w-full"
        placeholder="you@example.com"
        :description="__('Brief description of your repository')"
    />
</div>
