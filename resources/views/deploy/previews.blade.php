@php($project = $overview->project)
@php($statusTone = fn (string $status): string => match ($status) { 'ready' => 'success', 'failed' => 'danger', 'deploying', 'provisioning' => 'info', default => 'neutral' })
@php($statusLabel = fn (string $status): string => match ($status) { 'ready' => __('Ready'), 'failed' => __('Failed'), 'deploying' => __('Deploying'), 'provisioning' => __('Setting up'), default => __('Closed') })

<x-signal.layouts.project :overview="$overview" :title="__('Previews')" :description="__('Each pull request into a repository’s branch, and any branch you open one for, gets its own website, environment and deploys, removed when the pull request closes or the preview expires.')">
    @error('secret_keys')<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror

    <div class="grid gap-4 sm:grid-cols-2">
        <x-signal.ui.stat :label="__('Open previews')" :value="$limit === null ? (string) $used : __(':used of :limit', ['used' => $used, 'limit' => $limit])" :description="__('Across the account. Each also uses a website.')" />
        <x-signal.ui.stat :label="__('Repositories with previews')" :value="$repositories->isEmpty() ? __('None') : $repositories->pluck('name')->implode(', ')" :description="$allowed ? __('Turn previews on in a repository’s settings; its push webhook must be on too.') : __('Previews come with the Pro Deploy plan and above.')" />
    </div>

    @if ($allowed && $repositories->isNotEmpty())
        <x-signal.ui.settings-section id="branch-preview" :title="__('Preview a branch')" :description="__('Spin up a preview of any branch, not only a pull request. It deploys the branch’s latest commit and closes on the date you choose; open it again to deploy newer commits or move the date.')">
            <form method="POST" action="{{ route('deploy.previews.branch', $project) }}" class="grid gap-3 p-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_10rem_auto] sm:items-end sm:p-6">
                @csrf
                <x-signal.ui.select-field name="repository_id" :label="__('Repository')">
                    @foreach ($repositories as $repository)
                        <option value="{{ $repository->id }}">{{ $repository->name }}</option>
                    @endforeach
                </x-signal.ui.select-field>
                <x-signal.ui.input-field name="branch" :label="__('Branch')" maxlength="200" required placeholder="feature/new-checkout" />
                <x-signal.ui.select-field name="days" :label="__('Closes after')">
                    @foreach ([1, 3, 7, 14, 30] as $days)
                        <option value="{{ $days }}" @selected($days === 7)>{{ trans_choice(':count day|:count days', $days, ['count' => $days]) }}</option>
                    @endforeach
                </x-signal.ui.select-field>
                <x-signal.ui.button type="submit" variant="secondary">{{ __('Open preview') }}</x-signal.ui.button>
            </form>
        </x-signal.ui.settings-section>
    @endif

    @if ($open->isEmpty() && $closed->isEmpty())
        <x-signal.ui.empty-state icon="cloud-upload" :title="__('No previews yet')" :description="__('Open a pull request into a repository that has previews on.')" />
    @endif

    @foreach ($open as $preview)
        @php($approval = $preview->secretApprovals->firstWhere('revision', $preview->revision))
        <x-signal.ui.settings-section :title="$preview->sourceRepository->name.' · '.$preview->label()" :description="$preview->pull_request_number !== null ? $preview->title : null">
            <div class="grid gap-4 p-4 sm:p-6">
                <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <div class="space-y-1">
                        <p><x-signal.ui.badge :tone="$statusTone($preview->status)">{{ $statusLabel($preview->status) }}</x-signal.ui.badge>
                            <span class="font-mono text-xs text-muted">{{ $preview->source_branch }} · {{ $preview->shortRevision() }}</span></p>
                        @if ($preview->url)<p><a href="https://{{ $preview->url }}" class="font-bold text-primary hover:underline" rel="noopener" target="_blank">{{ $preview->url }}</a></p>@endif
                        <p class="text-xs text-muted">{{ $preview->pull_request_number !== null ? __('Closes :when unless the pull request changes.', ['when' => $preview->expiresAt()->diffForHumans()]) : __('Closes :when.', ['when' => $preview->expiresAt()->diffForHumans()]) }}
                            @if ($preview->repository)· <a href="{{ route('deploy.repositories.show', [$project, $preview->repository->id]) }}" class="text-primary hover:underline">{{ __('Deploys') }}</a>@endif
                            @if ($preview->website?->provisioning_status === 'failed')· <span class="text-danger">{{ $preview->website->provisioning_error ?? __('The website couldn’t be set up.') }}</span>@endif
                        </p>
                    </div>
                    @can('operate', $preview)
                        <form method="POST" action="{{ route('deploy.previews.close', [$project, $preview->id]) }}">@csrf<x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Close preview') }}</x-signal.ui.button></form>
                    @endcan
                </div>

                <div class="space-y-2 border-t border-line pt-4 text-sm">
                    <p class="font-bold">{{ __('Secrets') }}</p>
                    @if ($approval)
                        <p class="text-muted">{{ trans_choice(':count secret approved for this revision by :name.|:count secrets approved for this revision by :name.', count($approval->variable_versions), ['name' => $approval->approver?->name ?? __('someone')]) }}</p>
                    @else
                        <p class="text-muted">{{ __('None. The preview has its own key, URL and database, and the source environment’s non-secret variables.') }}</p>
                    @endif
                    @if (isset($approvable[$preview->id]) && $approvable[$preview->id] !== [])
                        <form method="POST" action="{{ route('deploy.previews.secrets', [$project, $preview->id]) }}" class="space-y-2">
                            @csrf
                            <input type="hidden" name="revision" value="{{ $preview->revision }}">
                            <p class="text-xs text-muted">{{ __('Review revision :revision first: approved secrets reach the pull request’s code. A new revision needs a new approval.', ['revision' => $preview->shortRevision()]) }}</p>
                            <div class="flex flex-wrap gap-3">
                                @foreach ($approvable[$preview->id] as $key)
                                    <x-signal.ui.checkbox :id="'secret-'.$preview->id.'-'.$key" name="secret_keys[]" :value="$key" :restore="false" :show-errors="false">{{ $key }}</x-signal.ui.checkbox>
                                @endforeach
                            </div>
                            <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Approve for this revision') }}</x-signal.ui.button>
                        </form>
                    @endif
                </div>
            </div>
        </x-signal.ui.settings-section>
    @endforeach

    @if ($closed->isNotEmpty())
        <x-signal.ui.table :caption="__('Recently closed')">
            <x-slot:head><tr><th scope="col">{{ __('Pull request') }}</th><th scope="col">{{ __('Closed') }}</th><th scope="col">{{ __('Cleanup') }}</th></tr></x-slot:head>
            @foreach ($closed as $preview)
                <tr>
                    <td>{{ $preview->sourceRepository->name }} · {{ $preview->label() }} @if ($preview->pull_request_number !== null)<span class="text-muted">{{ $preview->title }}</span>@endif</td>
                    <td>{{ $preview->closed_at?->diffForHumans() }}</td>
                    <td>
                        @switch($preview->cleanup_status)
                            @case('succeeded') <x-signal.ui.badge tone="success">{{ __('Removed') }}</x-signal.ui.badge> @break
                            @case('failed')
                                <x-signal.ui.badge tone="danger">{{ __('Failed') }}</x-signal.ui.badge>
                                <span class="block text-xs text-muted">{{ $preview->cleanup_error }}</span>
                                @can('operate', $preview)
                                    <form method="POST" action="{{ route('deploy.previews.cleanup', [$project, $preview->id]) }}" class="mt-1">@csrf<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Retry') }}</x-signal.ui.button></form>
                                @endcan
                                @break
                            @case(null) <span class="text-muted">{{ __('Waiting for a deploy to finish') }}</span> @break
                            @default <x-signal.ui.badge tone="info">{{ __('Removing') }}</x-signal.ui.badge>
                        @endswitch
                    </td>
                </tr>
            @endforeach
        </x-signal.ui.table>
    @endif
</x-signal.layouts.project>
