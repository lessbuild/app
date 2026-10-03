@php($project = $overview->project)
@php($secrets = session('secrets'))

<x-signal.layouts.project :overview="$overview" :title="$repository->name" :description="$repository->url.' · '.$repository->branch.' → '.$repository->website->name">
    @foreach (['deploy', 'rollback'] as $key)
        @error($key)<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror
    @endforeach
    @if (is_array($secrets) && isset($secrets['webhook']))
        <x-signal.ui.panel as="section" class="space-y-3 border-warning p-6">
            <p class="ui-eyebrow">{{ __('Copy it now') }}</p>
            <h2 class="text-lg font-extrabold text-ink">{{ __('Webhook secret') }}</h2>
            <p class="text-sm text-muted">{{ __('Add a push webhook with this URL and secret (JSON payloads). The secret is shown once.') }}</p>
            <x-signal.ui.code-block :code="route('webhooks.repositories.receive', $repository->id)" class="break-all" />
            <x-signal.ui.code-block :code="$secrets['webhook']" class="break-all" />
        </x-signal.ui.panel>
    @endif

    <x-signal.ui.card class="flex flex-wrap items-center justify-between gap-4 p-5">
        <div class="text-sm">
            <p>{{ __('Deploys to :website on :server', ['website' => $repository->website->name, 'server' => $repository->website->server?->label() ?? '—']) }}@if ($repository->environment) · {{ $repository->environment->name }}@endif</p>
            @unless ($repository->isDeploymentReady())<p class="text-danger">{{ __('Not ready: the website must be live on an active server, with a Git provider for this address.') }}</p>@endunless
        </div>
        @if ($canDeploy)
            <form method="POST" action="{{ route('deploy.repositories.deploy', [$project, $repository->id]) }}">@csrf<x-signal.ui.button type="submit" variant="primary" :disabled="! $repository->isDeploymentReady()">{{ __('Deploy :branch', ['branch' => $repository->branch]) }}</x-signal.ui.button></form>
            <x-signal.ui.button :href="request()->fullUrlWithQuery(['dialog' => 'deploy-ref'])" variant="secondary" data-modal-trigger="deploy-ref" :disabled="! $repository->isDeploymentReady()">{{ __('Deploy a version…') }}</x-signal.ui.button>
            <x-signal.overlays.form-modal id="deploy-ref" :title="__('Deploy a specific version')" :description="__('Deploy another branch, a release tag, or an exact commit. The environment’s approvals, locks and windows still apply.')" :action="route('deploy.repositories.deploy', [$project, $repository->id])" :submit="__('Deploy')">
                <x-signal.ui.input-field id="deploy-ref-input" name="ref" :label="__('Branch, tag or commit')" placeholder="v1.4.0" maxlength="200" autocomplete="off" required />
            </x-signal.overlays.form-modal>
            <x-signal.ui.button :href="request()->fullUrlWithQuery(['dialog' => 'schedule-deploy'])" variant="quiet" data-modal-trigger="schedule-deploy">{{ __('Deploy later…') }}</x-signal.ui.button>
            <x-signal.overlays.form-modal id="schedule-deploy" :title="__('Book a deploy')" :description="__('Deploy at a set time, such as a quiet hour tonight. It runs as you, and the environment’s approvals, locks, windows and freezes apply then.')" :action="route('deploy.repositories.scheduled-deploys.store', [$project, $repository->id])" :submit="__('Book deploy')" form-class="grid items-start gap-5 sm:grid-cols-2">
                <x-signal.ui.input-field id="schedule-deploy-at" name="deploy_at" type="datetime-local" :label="__('When')" required />
                <x-signal.ui.input-field id="schedule-deploy-timezone" name="timezone" :label="__('Time zone')" :value="old('timezone', $repository->environment?->deployment_window_timezone ?? 'UTC')" maxlength="64" required />
                <div class="sm:col-span-2"><x-signal.ui.input-field id="schedule-deploy-ref" name="ref" :label="__('Branch, tag or commit')" :description="__('Optional. Leave empty for the latest :branch.', ['branch' => $repository->branch])" maxlength="200" autocomplete="off" /></div>
            </x-signal.overlays.form-modal>
        @endif
    </x-signal.ui.card>

    @if ($scheduledDeploys->isNotEmpty())
        <x-signal.ui.card as="section" class="grid gap-2 p-5" aria-labelledby="booked-heading">
            <h2 id="booked-heading" class="text-sm font-extrabold text-ink">{{ __('Booked deploys') }}</h2>
            @foreach ($scheduledDeploys as $booked)
                <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <span><time datetime="{{ $booked->run_at->toIso8601String() }}" class="font-semibold">{{ $booked->run_at->toDayDateTimeString() }} UTC</time> · {{ $booked->git_ref ?? $repository->branch }} · <span class="text-muted">{{ $booked->creator?->name ?? __('Someone') }}</span></span>
                    @if ($canDeploy)
                        <form method="POST" action="{{ route('deploy.repositories.scheduled-deploys.destroy', [$project, $repository->id, $booked->id]) }}">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Cancel') }}</x-signal.ui.button></form>
                    @endif
                </div>
            @endforeach
        </x-signal.ui.card>
    @endif

    <x-signal.ui.page-tabs :tabs="$tabs" :current="$tab" :url="route('deploy.repositories.show', [$project, $repository->id])" />

    <x-signal.ui.page-tab-panel name="deploys" :current="$tab">
    <x-signal.ui.settings-section :title="__('Deploys')" :description="__('Newest first. Open one for its log, or to redeploy or roll back.')">
        @if ($builds->isEmpty())
            <p class="p-4 text-sm text-muted sm:p-6">{{ __('No deploys yet.') }}</p>
        @else
            <ul class="divide-y divide-line">
                @foreach ($builds as $build)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6">
                        <a href="{{ route('deploy.builds.show', [$project, $build->id]) }}" class="min-w-0 text-sm">
                            <span class="font-bold text-primary">#{{ $build->id }}</span>
                            <span class="font-mono text-xs text-muted">{{ $build->shortRevision() ?? '—' }}</span>
                            <span class="text-ink">{{ \Illuminate\Support\Str::limit($build->commit_message ?? '', 70) }}</span>
                            <span class="block text-xs text-muted">{{ __(ucfirst($build->trigger_source)) }} · {{ $build->requester?->name ?? __('Push') }} · {{ $build->created_at?->diffForHumans() }}</span>
                        </a>
                        @include('deploy._build-status', ['status' => $build->status])
                    </li>
                @endforeach
            </ul>
        @endif
    </x-signal.ui.settings-section>

    </x-signal.ui.page-tab-panel>

    <x-signal.ui.page-tab-panel name="webhook" :current="$tab">
    <x-signal.ui.settings-section id="webhook" :title="__('Push deploys')" :description="__('A webhook from :host deploys each push to :branch.', ['host' => $repository->provider?->repositoryHost() ?? __('your Git host'), 'branch' => $repository->branch])">
        <div class="grid gap-4 p-4 sm:p-6">
            <p class="text-sm">{{ $repository->webhook_enabled ? __('On') : __('Off') }}@if ($repository->webhook_last_received_at) · {{ __('last push :when', ['when' => $repository->webhook_last_received_at->diffForHumans()]) }}@endif</p>
            @if ($deliveries->isNotEmpty())
                <ul class="text-xs text-muted">
                    @foreach ($deliveries as $delivery)
                        <li><span class="font-mono">{{ substr((string) $delivery->revision, 0, 12) }}</span> · {{ __($delivery->status) }} · {{ $delivery->created_at?->diffForHumans() }}</li>
                    @endforeach
                </ul>
            @endif
            @if ($canManage)
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('deploy.repositories.webhook.store', [$project, $repository->id]) }}">@csrf<x-signal.ui.button type="submit" variant="secondary" size="sm">{{ $repository->webhook_enabled ? __('New secret') : __('Turn on') }}</x-signal.ui.button></form>
                    @if ($repository->webhook_enabled)
                        <form method="POST" action="{{ route('deploy.repositories.webhook.destroy', [$project, $repository->id]) }}">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Turn off') }}</x-signal.ui.button></form>
                    @endif
                </div>
            @endif
        </div>
    </x-signal.ui.settings-section>

    </x-signal.ui.page-tab-panel>

    @if ($canManage)
    <x-signal.ui.page-tab-panel name="settings" :current="$tab">
        <x-signal.ui.settings-section id="build-cache" :title="__('Build cache')" :description="__('Keeps Composer, npm, Yarn, pnpm and pip downloads on the server between deploys, so installs are faster. Clear it if a dependency seems stuck.')">
            <div class="flex flex-wrap items-center gap-3 p-4 sm:p-6">
                <form method="POST" action="{{ route('deploy.repositories.build-cache', [$project, $repository->id]) }}" class="flex flex-wrap items-center gap-3">
                    @csrf
                    @method('PUT')
                    <x-signal.ui.checkbox id="build-cache-enabled" name="build_cache_enabled" value="1" unchecked-value="0" :checked="$repository->build_cache_enabled" :restore="false">{{ __('Cache dependencies between deploys') }}</x-signal.ui.checkbox>
                    <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Save') }}</x-signal.ui.button>
                </form>
                @if ($repository->build_cache_enabled)
                    <form method="POST" action="{{ route('deploy.repositories.build-cache', [$project, $repository->id]) }}">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="build_cache_enabled" value="1">
                        <input type="hidden" name="clear" value="1">
                        <x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Clear build cache') }}</x-signal.ui.button>
                    </form>
                @endif
            </div>
        </x-signal.ui.settings-section>
        <x-signal.ui.settings-section :title="__('Settings')" :description="__('Changes apply to the next deploy.')">
            <form method="POST" action="{{ route('deploy.repositories.update', [$project, $repository->id]) }}" class="grid items-start gap-5 p-4 sm:grid-cols-2 sm:p-6">
                @csrf
                @method('PUT')
                @include('deploy._repository-fields', ['repository' => $repository])
                <div class="sm:col-span-2"><x-signal.ui.button type="submit" variant="primary">{{ __('Save repository') }}</x-signal.ui.button></div>
            </form>
        </x-signal.ui.settings-section>
        @unless ($repository->preview)
        <x-signal.ui.settings-section id="previews" :title="__('Pull-request previews')" :description="__('Each pull request into :branch gets its own website on :server, deployed from its branch. Push deploys must be on for the webhook to arrive; forks don’t get previews.', ['branch' => $repository->branch, 'server' => $repository->website->server?->label() ?? __('the website’s server')])">
            <form method="POST" action="{{ route('deploy.repositories.previews', [$project, $repository->id]) }}" class="grid items-start gap-5 p-4 sm:grid-cols-2 sm:p-6">
                @csrf
                @method('PUT')
                <div class="sm:col-span-2"><x-signal.ui.checkbox name="previews_enabled" value="1" :checked="$repository->previews_enabled">{{ __('Make previews of pull requests') }}</x-signal.ui.checkbox></div>
                <x-signal.ui.input-field name="preview_domain" :label="__('Preview domain')" :value="old('preview_domain', $repository->preview_domain)" placeholder="preview.example.com" maxlength="200" :description="__('Previews are served at pr-12-:project.<domain>; point wildcard DNS (*.<domain>) at the server.', ['project' => $project->slug])" />
                <x-signal.ui.input-field name="preview_ttl_hours" type="number" min="1" max="720" :label="__('Close after (hours without changes)')" :value="old('preview_ttl_hours', $repository->preview_ttl_hours)" required />
                <div class="sm:col-span-2"><x-signal.ui.textarea-field name="preview_initialization_command" :label="__('Set-up command (optional)')" rows="2" :value="old('preview_initialization_command', $repository->preview_initialization_command)" :description="__('Runs once on each new preview after its first deploy, e.g. php artisan migrate --seed.')" /></div>
                @php($siblings = $repository->website ? \App\Models\Website::query()->where('server_id', $repository->website->server_id)->whereKeyNot($repository->website->id)->whereNotIn('id', \App\Models\Preview::query()->whereNotNull('website_id')->select('website_id'))->orderBy('name')->get(['id', 'name']) : collect())
                <div class="sm:col-span-2">
                    <x-signal.ui.select-field name="preview_database_source_website_id" :label="__('Start each preview’s database from')" :description="__('New previews get a copy of this website’s database before their first deploy, so migrations and reviewers see real-looking data. Mind personal data: prefer a staging copy over production.')">
                        <option value="">{{ __('An empty database') }}</option>
                        @foreach ($siblings as $sibling)
                            <option value="{{ $sibling->id }}" @selected((int) old('preview_database_source_website_id', $repository->preview_database_source_website_id) === $sibling->id)>{{ __('A copy of :website', ['website' => $sibling->name]) }}</option>
                        @endforeach
                    </x-signal.ui.select-field>
                </div>
                <div class="sm:col-span-2">
                    <x-signal.ui.select-field name="preview_database_mode" :label="__('How much to copy')" :description="__('A sample takes the first :rows rows of each table: a quick branch of a big database. Schema only copies the tables without data, for your seeders to fill.', ['rows' => number_format(\App\Services\Infrastructure\DatabaseCommands::SAMPLE_ROWS)])">
                        @foreach (['full' => __('Everything'), 'sample' => __('A sample of each table'), 'schema' => __('Schema only')] as $value => $label)
                            <option value="{{ $value }}" @selected(old('preview_database_mode', $repository->preview_database_mode) === $value)>{{ $label }}</option>
                        @endforeach
                    </x-signal.ui.select-field>
                </div>
                <div class="sm:col-span-2"><x-signal.ui.checkbox name="preview_database_anonymise" value="1" unchecked-value="0" :checked="(bool) old('preview_database_anonymise', $repository->preview_database_anonymise)" :description="__('Emails, names, phone numbers, addresses, IP addresses and dates of birth are replaced in each preview’s copy, by column name.')">{{ __('Mask personal data') }}</x-signal.ui.checkbox></div>
                <div class="sm:col-span-2"><x-signal.ui.button type="submit" variant="primary">{{ __('Save preview settings') }}</x-signal.ui.button></div>
            </form>
        </x-signal.ui.settings-section>
        @endunless
        <x-signal.ui.settings-section :title="__('Remove this repository')" :description="__('Deploys stop; the website keeps its current release and the history stays.')">
            <div class="p-4 sm:p-6">
                <x-signal.ui.button variant="danger" data-modal-trigger="delete-repository">{{ __('Remove repository') }}</x-signal.ui.button>
                <x-signal.overlays.delete-confirmation id="delete-repository" :route="route('deploy.repositories.destroy', [$project, $repository->id])" :title="__('Remove :name?', ['name' => $repository->name])" :description="__('The website and its releases aren’t touched.')" :submit-label="__('Remove repository')" />
            </div>
        </x-signal.ui.settings-section>
    </x-signal.ui.page-tab-panel>
    @endif
</x-signal.layouts.project>
