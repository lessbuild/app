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
            <p class="text-xs text-muted">{{ __('Custom events: window.buildpusher.track(\'signup\'). Add revenue to measure what goals and campaigns earn: window.buildpusher.track(\'purchase\', {revenue: 49.99, currency: \'EUR\'}).') }}</p>
            <div class="grid gap-2 text-sm">
                <p class="font-bold text-ink">{{ __('Optional: count more automatically') }}</p>
                <ul class="grid gap-1.5 text-muted">
                    <li><code class="font-mono text-ink">data-outbound</code> · {{ __('clicks on links to other sites') }}</li>
                    <li><code class="font-mono text-ink">data-downloads</code> · {{ __('file downloads (PDFs, zips, documents and more), or list your own: data-downloads="pdf,zip"') }}</li>
                    <li><code class="font-mono text-ink">data-vitals</code> · {{ __('page speed (Core Web Vitals) as real visitors experience it') }}</li>
                    <li><code class="font-mono text-ink">data-not-found</code> · {{ __('on your 404 page only, to see which missing pages people reach') }}</li>
                </ul>
                <x-signal.ui.code-block :code="str_replace(' src=', ' data-outbound data-downloads data-vitals src=', $snippet)" class="whitespace-pre-wrap break-all" />
                <p class="text-muted">{{ __('To leave your own visits out, open any page of the site once with ?bp_ignore=1 in each browser you use (?bp_ignore=0 counts it again).') }}</p>
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
        <x-signal.ui.settings-section :title="__('Google Search Console')" :description="__('See which Google searches bring people to the site, next to its visits. Read-only access; Google’s figures lag by about two days.')">
            <div class="grid gap-4 p-4 sm:p-6">
                @error('search_console')<x-signal.ui.alert tone="danger">{{ $message }}</x-signal.ui.alert>@enderror
                @if (! $searchConsole['configured'] && ! $site->search_console_token)
                    <p class="text-sm text-muted">{{ __('Search Console isn’t set up on this platform yet: it needs a Google OAuth client (GOOGLE_SEARCH_CONSOLE_CLIENT_ID and GOOGLE_SEARCH_CONSOLE_CLIENT_SECRET).') }}</p>
                @elseif (! $site->search_console_token)
                    <form method="POST" action="{{ route('analytics.sites.search-console.connect', [$project, $site->id]) }}">
                        @csrf
                        <x-signal.ui.button type="submit" variant="primary">{{ __('Connect Google Search Console') }}</x-signal.ui.button>
                    </form>
                @else
                    @if ($searchConsole['error'])<x-signal.ui.alert tone="warning">{{ $searchConsole['error'] }}</x-signal.ui.alert>@endif
                    <form method="POST" action="{{ route('analytics.sites.search-console.property', [$project, $site->id]) }}" class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                        @csrf @method('PUT')
                        <x-signal.ui.select-field name="search_console_property" :label="__('Property')" :description="$site->search_console_property ? null : __('Choose the property for this site.')">
                            @if (! $site->search_console_property)<option value="">{{ __('Choose…') }}</option>@endif
                            @foreach ($searchConsole['properties'] as $property)
                                <option value="{{ $property }}" @selected($site->search_console_property === $property)>{{ $property }}</option>
                            @endforeach
                        </x-signal.ui.select-field>
                        <x-signal.ui.button type="submit" variant="secondary">{{ __('Use this property') }}</x-signal.ui.button>
                    </form>
                    <form method="POST" action="{{ route('analytics.sites.search-console.disconnect', [$project, $site->id]) }}">
                        @csrf @method('DELETE')
                        <x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Disconnect') }}</x-signal.ui.button>
                    </form>
                @endif
            </div>
        </x-signal.ui.settings-section>

        <x-signal.ui.settings-section :title="__('Reports and alerts')" :description="__('Email or Slack a summary every week (Mondays) or month (the 1st), from 8am in the site’s time zone, or an alert when a lot of people are on the site at once.')">
            <div class="grid gap-4 p-4 sm:p-6">
                @forelse ($site->notifications()->orderBy('id')->get() as $notification)
                    <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                        <span>
                            <span class="font-bold text-ink">{{ __(\App\Models\AnalyticsNotification::KINDS[$notification->kind] ?? $notification->kind) }}</span>
                            <span class="text-muted">· {{ $notification->destination() }}@if ($notification->threshold) · {{ trans_choice('at :count visitor|at :count visitors', $notification->threshold, ['count' => number_format($notification->threshold)]) }}@endif @if ($notification->last_sent_at) · {{ __('last sent :time', ['time' => $notification->last_sent_at->diffForHumans()]) }}@endif</span>
                            @if ($notification->last_error)<span class="block text-xs text-danger">{{ $notification->last_error }}</span>@endif
                        </span>
                        <form method="POST" action="{{ route('analytics.sites.notifications.destroy', [$project, $site->id, $notification->id]) }}">
                            @csrf @method('DELETE')
                            <x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Remove') }}</x-signal.ui.button>
                        </form>
                    </div>
                @empty
                    <p class="text-sm text-muted">{{ __('No reports or alerts yet.') }}</p>
                @endforelse
                <form method="POST" action="{{ route('analytics.sites.notifications.store', [$project, $site->id]) }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
                    @csrf
                    <x-signal.ui.select-field name="kind" :label="__('What')">
                        @foreach (\App\Models\AnalyticsNotification::KINDS as $value => $label)
                            <option value="{{ $value }}" @selected(old('kind') === $value)>{{ __($label) }}</option>
                        @endforeach
                    </x-signal.ui.select-field>
                    <x-signal.ui.select-field name="channel" :label="__('How')">
                        @foreach (\App\Models\AnalyticsNotification::CHANNELS as $value => $label)
                            <option value="{{ $value }}" @selected(old('channel') === $value)>{{ __($label) }}</option>
                        @endforeach
                    </x-signal.ui.select-field>
                    <x-signal.ui.input-field name="target" :label="__('Email or Slack webhook address')" maxlength="500" required />
                    <x-signal.ui.input-field name="threshold" type="number" min="1" :label="__('Spike at (current visitors)')" :description="__('For spike alerts only.')" />
                    <div class="sm:col-span-2 lg:col-span-4"><x-signal.ui.button type="submit" variant="secondary">{{ __('Add') }}</x-signal.ui.button></div>
                </form>
            </div>
        </x-signal.ui.settings-section>

        <x-signal.ui.settings-section :title="__('Share the report')" :description="__('Give clients or your team a read-only link to this site’s report. They don’t need an account. Releases and goal settings aren’t shown.')">
            <div class="grid gap-4 p-4 sm:p-6">
                @if ($site->share_token)
                    <x-signal.ui.code-block :code="route('analytics.shared', $site->share_token)" class="whitespace-pre-wrap break-all" />
                    <p class="text-sm text-muted">{{ $site->share_password ? __('Protected by a password. Shared :time.', ['time' => $site->shared_at?->diffForHumans()]) : __('Anyone with the link can see it. Shared :time.', ['time' => $site->shared_at?->diffForHumans()]) }}</p>
                @endif
                <form method="POST" action="{{ route('analytics.sites.share', [$project, $site->id]) }}" class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                    @csrf
                    <x-signal.ui.input-field name="share_password" type="password" :label="__('Password (optional)')" :description="__('At least 8 characters. Leave empty for no password.')" autocomplete="new-password" />
                    <div class="flex flex-wrap items-center gap-3">
                        @if ($site->share_token)
                            <x-signal.ui.checkbox name="new_link" :show-errors="false">{{ __('New link (the old one stops working)') }}</x-signal.ui.checkbox>
                        @endif
                        <x-signal.ui.button type="submit" variant="primary">{{ $site->share_token ? __('Update sharing') : __('Create link') }}</x-signal.ui.button>
                    </div>
                </form>
                @if ($site->share_token)
                    <form method="POST" action="{{ route('analytics.sites.unshare', [$project, $site->id]) }}">
                        @csrf @method('DELETE')
                        <x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Stop sharing') }}</x-signal.ui.button>
                    </form>
                @endif
            </div>
        </x-signal.ui.settings-section>

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
