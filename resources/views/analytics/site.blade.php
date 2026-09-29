@php($project = $overview->project)
@php($snippet = '<script defer data-site="'.$site->public_id.'" src="'.url('/tracker/v1.js').'"></script>')

<x-signal.layouts.project :overview="$overview" :title="$site->name" :description="implode(', ', $site->domains)">
    @foreach (['domains', 'environment_id'] as $field)
        @error($field)
            <x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>
        @enderror
    @endforeach

    <x-signal.ui.settings-section :title="__('Tracking snippet')" :description="__('Paste it into the <head> of every page. It sets no cookies and respects an optional consent callback (data-consent).')">
        <div class="grid gap-4 p-4 sm:p-6">
            <x-signal.ui.code-block :code="$snippet" class="whitespace-pre-wrap break-all" />
            <p class="text-xs text-muted">{{ __('Custom events: window.buildpusher.track(\'signup\').') }}</p>
            <div class="grid gap-2 text-sm">
                <p class="font-bold text-ink">{{ __('Optional: count more automatically') }}</p>
                <ul class="grid gap-1.5 text-muted">
                    <li><code class="font-mono text-ink">data-outbound</code> · {{ __('clicks on links to other sites') }}</li>
                    <li><code class="font-mono text-ink">data-downloads</code> · {{ __('file downloads (PDFs, zips, documents and more), or list your own: data-downloads="pdf,zip"') }}</li>
                    <li><code class="font-mono text-ink">data-not-found</code> · {{ __('on your 404 page only, to see which missing pages people reach') }}</li>
                </ul>
                <x-signal.ui.code-block :code="str_replace(' src=', ' data-outbound data-downloads src=', $snippet)" class="whitespace-pre-wrap break-all" />
            </div>
        </div>
    </x-signal.ui.settings-section>

    <x-signal.ui.settings-section :title="__('Verification')" :description="__('Data is only accepted once one of the site’s hostnames is a verified domain of :project.', ['project' => $project->name])">
        <div class="flex flex-wrap items-center justify-between gap-3 p-4 sm:p-6">
            <x-signal.ui.badge :tone="$site->isVerified() ? 'success' : 'warning'">{{ $site->isVerified() ? __('Verified :time', ['time' => $site->verified_at?->diffForHumans()]) : __('Not verified') }}</x-signal.ui.badge>
            @if (! $site->isVerified() && $canManage)
                <div class="flex flex-wrap gap-2">
                    <x-signal.ui.button :href="route('projects.domains', $project)" variant="quiet" size="sm">{{ __('Project domains') }}</x-signal.ui.button>
                    <form method="POST" action="{{ route('analytics.sites.verify', [$project, $site->id]) }}">
                        @csrf
                        <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Check again') }}</x-signal.ui.button>
                    </form>
                </div>
            @endif
        </div>
    </x-signal.ui.settings-section>

    @if ($canManage)
        <x-signal.ui.settings-section :title="__('Settings')" :description="__('Changing hostnames takes effect for the next visit.')">
            <form method="POST" action="{{ route('analytics.sites.update', [$project, $site->id]) }}" class="grid gap-5 p-4 sm:p-6">
                @csrf
                @method('PUT')
                @include('analytics._site-fields', ['site' => $site])
                <div><x-signal.ui.button type="submit" variant="primary">{{ __('Save') }}</x-signal.ui.button></div>
            </form>
        </x-signal.ui.settings-section>

        <x-signal.ui.settings-section :title="__('Delete this site')" :description="__('Deletes its visits, goals and reports. The snippet stops being accepted immediately.')">
            <div class="p-4 sm:p-6">
                <x-signal.ui.button variant="danger" data-modal-trigger="delete-site">{{ __('Delete :site', ['site' => $site->name]) }}</x-signal.ui.button>
                <x-signal.overlays.delete-confirmation id="delete-site" :route="route('analytics.sites.destroy', [$project, $site->id])" :title="__('Delete :site?', ['site' => $site->name])" :description="__('All of its analytics data is deleted.')" :submit-label="__('Delete site')" />
            </div>
        </x-signal.ui.settings-section>
    @endif
</x-signal.layouts.project>
