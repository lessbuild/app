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
        @endif
    </x-signal.ui.card>

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

    <x-signal.ui.settings-section id="webhook" :title="__('Push deploys')" :description="__('A webhook from :host deploys each push to :branch.', ['host' => $repository->provider?->type->repositoryHost() ?? __('your Git host'), 'branch' => $repository->branch])">
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

    @if ($canManage)
        <x-signal.ui.settings-section :title="__('Settings')" :description="__('Changes apply to the next deploy.')">
            <form method="POST" action="{{ route('deploy.repositories.update', [$project, $repository->id]) }}" class="grid items-start gap-5 p-4 sm:grid-cols-2 sm:p-6">
                @csrf
                @method('PUT')
                @include('deploy._repository-fields', ['repository' => $repository])
                <div class="sm:col-span-2"><x-signal.ui.button type="submit" variant="primary">{{ __('Save repository') }}</x-signal.ui.button></div>
            </form>
        </x-signal.ui.settings-section>
        <x-signal.ui.settings-section :title="__('Remove this repository')" :description="__('Deploys stop; the website keeps its current release and the history stays.')">
            <div class="p-4 sm:p-6">
                <x-signal.ui.button variant="danger" data-modal-trigger="delete-repository">{{ __('Remove repository') }}</x-signal.ui.button>
                <x-signal.overlays.delete-confirmation id="delete-repository" :route="route('deploy.repositories.destroy', [$project, $repository->id])" :title="__('Remove :name?', ['name' => $repository->name])" :description="__('The website and its releases aren’t touched.')" :submit-label="__('Remove repository')" />
            </div>
        </x-signal.ui.settings-section>
    @endif
</x-signal.layouts.project>
