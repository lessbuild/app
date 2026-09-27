@php($repository ??= null)
<x-signal.ui.input-field name="name" :label="__('Name')" :value="old('name', $repository?->name)" maxlength="120" required />
<x-signal.ui.select-field name="provider_id" :label="__('Git provider')" :description="$providers->isEmpty() ? __('Add a GitHub, GitLab or Bitbucket token under Account → Providers first.') : null">
    @foreach ($providers as $provider)
        <option value="{{ $provider->id }}" @selected((int) old('provider_id', $repository?->provider_id ?? request()->integer('provider_id')) === $provider->id)>{{ $provider->name }} ({{ $provider->isGitHubApp() ? __('GitHub App') : $provider->type->label() }})</option>
    @endforeach
</x-signal.ui.select-field>
<x-signal.ui.input-field name="url" :label="__('Repository')" :value="old('url', $repository?->url ?? request()->string('url')->toString())" placeholder="github.com/acme/shop" maxlength="255" required />
<x-signal.ui.input-field name="branch" :label="__('Branch')" :value="old('branch', $repository?->branch ?? (request()->string('branch')->toString() ?: 'main'))" maxlength="255" required />
<x-signal.ui.select-field name="website_id" :label="__('Deploys to website')">
    @foreach ($websites as $website)
        <option value="{{ $website->id }}" @selected((int) old('website_id', $repository?->website_id) === $website->id)>{{ $website->name }} ({{ $website->url }})</option>
    @endforeach
</x-signal.ui.select-field>
<x-signal.ui.select-field name="environment_id" :label="__('Environment')" :description="__('Deploys show up in its Monitoring releases.')">
    <option value="">{{ __('None') }}</option>
    @foreach ($environments as $environment)
        <option value="{{ $environment->id }}" @selected(old('environment_id', $repository?->environment_id) === $environment->id)>{{ $environment->name }}</option>
    @endforeach
</x-signal.ui.select-field>
<x-signal.ui.input-field name="deployment_root" :label="__('Subdirectory (optional)')" :value="old('deployment_root', $repository?->deployment_root)" placeholder="apps/api" maxlength="512" />
<div class="sm:col-span-2 grid gap-5 sm:grid-cols-2">
    <x-signal.ui.textarea-field name="build_commands" :label="__('Build commands')" rows="3" :value="old('build_commands', $repository?->build_commands)" :description="__('Run in the new release before it goes live.')" />
    <x-signal.ui.textarea-field name="post_deployment_commands" :label="__('After-deploy commands')" rows="3" :value="old('post_deployment_commands', $repository?->post_deployment_commands)" :description="__('Run once the release is live.')" />
    <x-signal.ui.textarea-field name="auto_deploy_include_paths" :label="__('Push deploys only for these paths')" rows="2" :value="old('auto_deploy_include_paths', implode(PHP_EOL, $repository?->auto_deploy_include_paths ?? []))" :description="__('One glob per line, e.g. app/**; leave empty for any change.')" />
    <x-signal.ui.textarea-field name="auto_deploy_exclude_paths" :label="__('Ignore pushes that only change')" rows="2" :value="old('auto_deploy_exclude_paths', implode(PHP_EOL, $repository?->auto_deploy_exclude_paths ?? []))" :description="__('One glob per line, e.g. docs/** or *.md.')" />
</div>
