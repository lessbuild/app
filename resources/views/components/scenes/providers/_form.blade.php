@props([
    'provider' => null,
    'fieldPrefix' => '',
])

@php
    $monitoringAllowed = app(\App\Services\Entitlements::class)->allows(auth()->user()->currentOrganization, 'monitoring');
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
            <label class="ui-choice relative min-h-16 cursor-pointer items-center focus-within:ring-2 focus-within:ring-focus">
                <input type="radio" name="provider" value="digitalocean" class="ui-check h-4 w-4 shrink-0" x-model="selectedProvider" required @checked($selectedProvider === 'digitalocean')>
                <span class="text-sm font-semibold text-ink">{{ __('DigitalOcean') }}</span>
            </label>

            <label class="ui-choice relative min-h-16 cursor-pointer items-center focus-within:ring-2 focus-within:ring-focus">
                <input type="radio" name="provider" value="github" class="ui-check h-4 w-4 shrink-0" x-model="selectedProvider" required @checked($selectedProvider === 'github')>
                <span class="text-sm font-semibold text-ink">{{ __('GitHub') }}</span>
            </label>

            <label class="ui-choice relative min-h-16 cursor-pointer items-center focus-within:ring-2 focus-within:ring-focus">
                <input type="radio" name="provider" value="gitlab" class="ui-check h-4 w-4 shrink-0" x-model="selectedProvider" required @checked($selectedProvider === 'gitlab')>
                <span class="text-sm font-semibold text-ink">{{ __('GitLab') }}</span>
            </label>

            <label class="ui-choice relative min-h-16 cursor-pointer items-center focus-within:ring-2 focus-within:ring-focus">
                <input type="radio" name="provider" value="bitbucket" class="ui-check h-4 w-4 shrink-0" x-model="selectedProvider" required @checked($selectedProvider === 'bitbucket')>
                <span class="text-sm font-semibold text-ink">{{ __('Bitbucket') }}</span>
            </label>

            <label class="ui-choice relative min-h-16 cursor-pointer items-center focus-within:ring-2 focus-within:ring-focus">
                <input type="radio" name="provider" value="hetzner" class="ui-check h-4 w-4 shrink-0" x-model="selectedProvider" required @checked($selectedProvider === 'hetzner')>
                <span class="text-sm font-semibold text-ink">{{ __('Hetzner Cloud') }}</span>
            </label>

            <label class="ui-choice relative min-h-16 cursor-pointer items-center focus-within:ring-2 focus-within:ring-focus">
                <input type="radio" name="provider" value="vultr" class="ui-check h-4 w-4 shrink-0" x-model="selectedProvider" required @checked($selectedProvider === 'vultr')>
                <span class="text-sm font-semibold text-ink">{{ __('Vultr') }}</span>
            </label>

            <label class="ui-choice relative min-h-16 cursor-pointer items-center focus-within:ring-2 focus-within:ring-focus">
                <input type="radio" name="provider" value="cloudflare" class="ui-check h-4 w-4 shrink-0" x-model="selectedProvider" required @checked($selectedProvider === 'cloudflare')>
                <span class="text-sm font-semibold text-ink">{{ __('Cloudflare DNS') }}</span>
            </label>
        </div>
        <x-forms.errors name="provider" />
    </fieldset>

    @if (! $isEditing && app(\App\Services\GitHubApp::class)->configured())
        <x-ui.alert tone="info" class="border-l-4 sm:col-span-2" x-cloak x-show="selectedProvider === 'github'">
            <div class="min-w-0">
                <p class="font-semibold text-ink">{{ __('Recommended for GitHub') }}</p>
                <p class="mt-1 text-sm text-muted">{{ __('Install the GitHub App to discover repositories and receive push events without storing a long-lived personal token.') }}</p>
                <x-ui.button :href="route('github-app.connect')" variant="secondary" class="mt-3">{{ __('Install GitHub App') }}</x-ui.button>
            </div>
        </x-ui.alert>
    @elseif (! $isEditing && config('github-app.setup_enabled') && auth()->user()?->isPlatformAdmin() && ! app(\App\Services\GitHubApp::class)->hasPrivateKey())
        <x-ui.alert tone="warning" class="border-l-4 sm:col-span-2" x-cloak x-show="selectedProvider === 'github'">
            <div class="min-w-0">
                <p class="font-semibold text-ink">{{ __('GitHub App setup is incomplete') }}</p>
                <p class="mt-1 text-sm text-muted">{{ __('A platform administrator can upload the downloaded private key from a phone.') }}</p>
                <x-ui.button :href="route('admin.github-app.setup')" variant="secondary" class="mt-3">{{ __('Set up GitHub App') }}</x-ui.button>
            </div>
        </x-ui.alert>
    @endif

    <div>
        <label for="{{ $fieldPrefix }}token" class="ui-label">{{ __('Provider Token') }}</label>
        <input
            value="{{ old('token') }}"
            type="password"
            name="token"
            id="{{ $fieldPrefix }}token"
            autocomplete="off"
            @if (! $isEditing) required @endif
            class="ui-input"
            placeholder="************"
        >
        <p class="ui-help">{{ __('Credentials are encrypted at rest and never shown in connection history.') }}</p>
        <x-forms.errors name="token" />
    </div>

    <div>
        <label for="{{ $fieldPrefix }}name" class="ui-label">{{ __('Provider Name') }}</label>
        <input
            value="{{ old('name') ?? ($provider?->name ?? null) }}"
            type="text"
            name="name"
            id="{{ $fieldPrefix }}name"
            class="ui-input"
            placeholder="Example: Source control access token"
        >
        <x-forms.errors name="name" />
    </div>

    <div class="sm:col-span-2">
        <label for="{{ $fieldPrefix }}description" class="ui-label">{{ __('Description') }}</label>
        <textarea
            id="{{ $fieldPrefix }}description"
            name="description"
            rows="3"
            class="ui-input min-h-24"
            placeholder="{{ __('Example: To manage one website') }}"
        >{{ old('description') ?? ($provider?->description ?? null) }}</textarea>
        <p class="ui-help">{{ __('Brief description of the provider token') }}</p>
        <x-forms.errors name="description" />
    </div>

    <details id="{{ $fieldPrefix }}provider-monitoring-settings" class="ui-responsive-details group ui-card ui-card--muted overflow-hidden sm:col-span-2" open data-responsive-details data-responsive-details-mobile-open="{{ $monitoringHasErrors ? 'true' : 'false' }}">
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
                <input type="hidden" name="connection_monitoring_enabled" value="0">
                <input
                    id="{{ $fieldPrefix }}connection_monitoring_enabled"
                    name="connection_monitoring_enabled"
                    type="checkbox"
                    value="1"
                    class="ui-check mt-1"
                    @checked($monitoringAllowed && (bool) old('connection_monitoring_enabled', $provider->connection_monitoring_enabled ?? true))
                    @disabled(! $monitoringAllowed)
                >
                <div>
                    <label for="{{ $fieldPrefix }}connection_monitoring_enabled" class="block text-sm font-semibold text-ink">
                        {{ __('Automatically monitor credential health') }}
                    </label>
                    <p class="mt-1 text-sm text-muted">
                        @if ($monitoringAllowed)
                            {{ __('Periodically verify this credential and alert on failures or recovery. Manual connection tests remain available when paused.') }}
                        @else
                            {{ __('Automatic checks require a plan with monitoring. You can add a provider and test its connection manually.') }}
                        @endif
                    </p>
                </div>
            </div>
            <x-forms.errors name="connection_monitoring_enabled" />

            <div class="mt-5 grid gap-5 border-t border-line pt-5 sm:grid-cols-2">
                <div>
                    <label for="{{ $fieldPrefix }}connection_check_interval_minutes" class="ui-label">{{ __('Automatic check interval') }}</label>
                    <select id="{{ $fieldPrefix }}connection_check_interval_minutes" name="connection_check_interval_minutes" class="ui-input">
                        @foreach (\App\Models\Provider::CONNECTION_CHECK_INTERVALS as $minutes)
                            @php($hours = intdiv($minutes, 60))
                            <option value="{{ $minutes }}" @selected((int) old('connection_check_interval_minutes', $provider?->connection_check_interval_minutes ?? \App\Models\Provider::defaultConnectionCheckInterval()) === $minutes)>
                                {{ trans_choice('Every :count hour|Every :count hours', $hours, ['count' => $hours]) }}
                            </option>
                        @endforeach
                    </select>
                    <p class="ui-help">{{ __('Applies to scheduled monitoring only. Manual connection tests can still run immediately.') }}</p>
                    <x-forms.errors name="connection_check_interval_minutes" />
                </div>

                <div>
                    <label for="{{ $fieldPrefix }}connection_failure_threshold" class="ui-label">{{ __('Failure confirmation') }}</label>
                    <select id="{{ $fieldPrefix }}connection_failure_threshold" name="connection_failure_threshold" class="ui-input">
                        @foreach (\App\Models\Provider::CONNECTION_FAILURE_THRESHOLDS as $failures)
                            <option value="{{ $failures }}" @selected((int) old('connection_failure_threshold', $provider?->connection_failure_threshold ?? \App\Models\Provider::defaultConnectionFailureThreshold()) === $failures)>
                                {{ trans_choice('After :count consecutive failure|After :count consecutive failures', $failures, ['count' => $failures]) }}
                            </option>
                        @endforeach
                    </select>
                    <p class="ui-help">{{ __('A successful check resets the count. One failure incident is created when this threshold is first reached.') }}</p>
                    <x-forms.errors name="connection_failure_threshold" />
                </div>
            </div>
        </fieldset>
    </details>
</div>
