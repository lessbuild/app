@props([
    'provider' => null,
    'fieldPrefix' => '',
])

@php
    $monitoringAllowed = app(\App\Modules\Deployer\Services\Entitlements::class)->allows(auth()->user()->currentOrganization, 'monitoring');
    $isEditing = $provider !== null;
    $selectedProvider = (string) old('provider', $provider?->provider ?? '');
    $monitoringHasErrors = $errors->hasAny([
        'connection_monitoring_enabled',
        'connection_check_interval_minutes',
        'connection_failure_threshold',
    ]);
@endphp

<div class="grid gap-5 bg-surface px-5 py-5 sm:grid-cols-2 sm:px-8" x-data="{ selectedProvider: @js($selectedProvider) }">

    <fieldset class="sm:col-span-2">
        <legend class="ui-label mb-0">{{ __('Provider') }}</legend>
        <p class="ui-help">{{ __('Choose the integration that owns this credential.') }}</p>
        <div class="mt-3 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4" role="radiogroup" aria-label="{{ __('Provider') }}">
            @foreach(['digitalocean' => __('DigitalOcean'), 'github' => __('GitHub'), 'gitlab' => __('GitLab'), 'bitbucket' => __('Bitbucket'), 'hetzner' => __('Hetzner Cloud'), 'vultr' => __('Vultr'), 'cloudflare' => __('Cloudflare DNS')] as $providerKey => $providerLabel)
                <x-signal.ui.choice
                    :id="$fieldPrefix.'provider-'.$providerKey"
                    name="provider"
                    :value="$providerKey"
                    :label="$providerLabel"
                    type="radio"
                    :checked="$selectedProvider === $providerKey"
                    :restore="false"
                    :card="true"
                    class="h-4 w-4 self-center"
                    x-model="selectedProvider"
                    required
                />
            @endforeach
        </div>
    </fieldset>

    @if (! $isEditing && app(\App\Modules\Deployer\Services\GitHubApp::class)->configured())
        <x-signal.ui.alert tone="info" class="border-l-4 sm:col-span-2" x-cloak x-show="selectedProvider === 'github'">
            <div class="min-w-0">
                <p class="font-semibold text-ink">{{ __('Recommended for GitHub') }}</p>
                <p class="mt-1 text-sm text-muted">{{ __('Install the GitHub App to discover repositories and receive push events without storing a long-lived personal token.') }}</p>
                <x-signal.ui.button :href="route('github-app.connect')" variant="secondary" class="mt-3">{{ __('Install GitHub App') }}</x-signal.ui.button>
            </div>
        </x-signal.ui.alert>
    @elseif (! $isEditing && config('github-app.setup_enabled') && auth()->user()?->isPlatformAdmin() && ! app(\App\Modules\Deployer\Services\GitHubApp::class)->hasPrivateKey())
        <x-signal.ui.alert tone="warning" class="border-l-4 sm:col-span-2" x-cloak x-show="selectedProvider === 'github'">
            <div class="min-w-0">
                <p class="font-semibold text-ink">{{ __('GitHub App setup is incomplete') }}</p>
                <p class="mt-1 text-sm text-muted">{{ __('A platform administrator can upload the downloaded private key from a phone.') }}</p>
                <x-signal.ui.button :href="route('admin.github-app.setup')" variant="secondary" class="mt-3">{{ __('Set up GitHub App') }}</x-signal.ui.button>
            </div>
        </x-signal.ui.alert>
    @endif

    <x-signal.ui.input-field
        :id="$fieldPrefix.'token'"
        name="token"
        :label="__('Provider Token')"
        type="password"
        autocomplete="off"
        placeholder="************"
        :restore="false"
        :required="! $isEditing"
        :description="__('Credentials are encrypted at rest and never shown in connection history.')"
    />

    <x-signal.ui.input-field
        :id="$fieldPrefix.'name'"
        name="name"
        :label="__('Provider Name')"
        :value="$provider?->name"
        placeholder="Example: Source control access token"
    />

    <x-signal.ui.textarea-field
        :id="$fieldPrefix.'description'"
        name="description"
        :label="__('Description')"
        :value="$provider?->description"
        :description="__('Brief description of the provider token')"
        :placeholder="__('Example: To manage one website')"
        rows="3"
        class="min-h-24"
    />

    <x-signal.ui.card as="details" tone="muted" class="ui-responsive-details group overflow-hidden sm:col-span-2" id="{{ $fieldPrefix }}provider-monitoring-settings" open data-responsive-details data-responsive-details-mobile-open="{{ $monitoringHasErrors ? 'true' : 'false' }}">
        <summary class="flex cursor-pointer list-none items-start justify-between gap-4 p-4 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus lg:hidden [&::-webkit-details-marker]:hidden">
            <span>
                <span class="block font-bold text-ink">{{ __('Connection monitoring') }}</span>
                <span class="ui-help block font-normal">{{ $monitoringAllowed ? __('Optional automatic credential health checks.') : __('Manual connection tests are available on your current plan.') }}</span>
            </span>
            <span class="shrink-0 text-xl font-normal text-muted transition group-open:rotate-45" aria-hidden="true">+</span>
        </summary>
        <fieldset class="ui-responsive-details__content border-t border-line p-4 lg:border-0">
            <legend class="sr-only">{{ __('Connection monitoring') }}</legend>
            <div class="flex items-start gap-3">
                <x-signal.ui.choice
                    id="{{ $fieldPrefix }}connection_monitoring_enabled"
                    name="connection_monitoring_enabled"
                    :label="__('Automatically monitor credential health')"
                    :description="$monitoringAllowed ? __('Periodically verify this credential and alert on failures or recovery. Manual connection tests remain available when paused.') : __('Automatic checks require a plan with monitoring. You can add a provider and test its connection manually.')"
                    type="checkbox"
                    value="1"
                    :checked="$monitoringAllowed && (bool) old('connection_monitoring_enabled', $provider->connection_monitoring_enabled ?? true)"
                    :restore="false"
                    unchecked-value="0"
                    :disabled="! $monitoringAllowed"
                    class="mt-1"
                />
            </div>

            <div class="mt-5 grid gap-5 border-t border-line pt-5 sm:grid-cols-2">
                <x-signal.ui.select-field :id="$fieldPrefix.'connection_check_interval_minutes'" name="connection_check_interval_minutes" :label="__('Automatic check interval')" :description="__('Applies to scheduled monitoring only. Manual connection tests can still run immediately.')">
                        @foreach (\App\Modules\Deployer\Models\Provider::CONNECTION_CHECK_INTERVALS as $minutes)
                            @php($hours = intdiv($minutes, 60))
                            <option value="{{ $minutes }}" @selected((int) old('connection_check_interval_minutes', $provider?->connection_check_interval_minutes ?? \App\Modules\Deployer\Models\Provider::defaultConnectionCheckInterval()) === $minutes)>
                                {{ trans_choice('Every :count hour|Every :count hours', $hours, ['count' => $hours]) }}
                            </option>
                        @endforeach
                </x-signal.ui.select-field>

                <x-signal.ui.select-field :id="$fieldPrefix.'connection_failure_threshold'" name="connection_failure_threshold" :label="__('Failure confirmation')" :description="__('A successful check resets the count. One failure incident is created when this threshold is first reached.')">
                        @foreach (\App\Modules\Deployer\Models\Provider::CONNECTION_FAILURE_THRESHOLDS as $failures)
                            <option value="{{ $failures }}" @selected((int) old('connection_failure_threshold', $provider?->connection_failure_threshold ?? \App\Modules\Deployer\Models\Provider::defaultConnectionFailureThreshold()) === $failures)>
                                {{ trans_choice('After :count consecutive failure|After :count consecutive failures', $failures, ['count' => $failures]) }}
                            </option>
                        @endforeach
                </x-signal.ui.select-field>
            </div>
        </fieldset>
    </x-signal.ui.card>
</div>
