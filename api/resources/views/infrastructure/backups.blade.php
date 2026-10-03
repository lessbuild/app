@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Backups')" :description="__('Websites are backed up with restic to S3-compatible storage: the database, the .env file and shared storage, encrypted before they leave the server.')">
    @error('destination')<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror

    <div class="grid gap-4 sm:grid-cols-3">
        <x-signal.ui.stat :label="__('Last backup')" :value="$summary['backup']?->diffForHumans() ?? __('None yet')" />
        <x-signal.ui.stat :label="__('Last restore')" :value="$summary['restore']?->diffForHumans() ?? __('None yet')" :description="$summary['restore_seconds'] !== null ? __('Took :duration', ['duration' => \Carbon\CarbonInterval::seconds($summary['restore_seconds'])->cascade()->forHumans(short: true)]) : null" />
        <x-signal.ui.stat :label="__('Last verified restore')" :value="$summary['verification']?->diffForHumans() ?? __('None yet')" />
    </div>

    <x-signal.ui.settings-section :title="__('Destinations')" :description="__('Buckets belong to :account; each website gets its own restic repository under the prefix.', ['account' => $project->account->name])">
        <div class="grid gap-4 p-4 sm:p-6">
            @forelse ($destinations as $destination)
                <div class="grid gap-3 rounded-panel border border-line p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-bold text-ink">{{ $destination->name }}</p>
                            <p class="text-xs text-muted">{{ $presets[$destination->storage_provider]['name'] ?? $destination->storage_provider }} · <span class="font-mono">{{ $destination->bucket }}/{{ $destination->path_prefix }}</span> · {{ trans_choice(':count schedule|:count schedules', $destination->schedules_count) }} · {{ trans_choice(':count backup|:count backups', $destination->backups_count) }}</p>
                        </div>
                        @if ($destination->last_error)
                            <x-signal.ui.badge tone="danger">{{ __('Check failed') }}</x-signal.ui.badge>
                        @elseif ($destination->last_verified_at)
                            <x-signal.ui.badge tone="success">{{ __('Works · :when', ['when' => $destination->last_verified_at->diffForHumans()]) }}</x-signal.ui.badge>
                        @else
                            <x-signal.ui.badge>{{ __('Not checked') }}</x-signal.ui.badge>
                        @endif
                    </div>
                    @if ($destination->last_error)
                        <p class="text-sm text-danger">{{ $destination->last_error }}</p>
                    @endif
                    @if ($canManage)
                        <div class="flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('infrastructure.backups.destinations.check', [$project, $destination->id]) }}">@csrf<x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Check connection') }}</x-signal.ui.button></form>
                            <x-signal.ui.button variant="quiet" size="sm" data-modal-trigger="delete-destination-{{ $destination->id }}">{{ __('Delete') }}</x-signal.ui.button>
                        </div>
                        <x-signal.ui.disclosure :title="__('Edit')">
                            <form method="POST" action="{{ route('infrastructure.backups.destinations.update', [$project, $destination->id]) }}" class="grid items-start gap-4 sm:grid-cols-2">
                                @csrf
                                @method('PUT')
                                @include('infrastructure._backup-destination-fields', ['destination' => $destination])
                                <div class="sm:col-span-2"><x-signal.ui.button type="submit" variant="primary">{{ __('Save destination') }}</x-signal.ui.button></div>
                            </form>
                        </x-signal.ui.disclosure>
                        <x-signal.overlays.delete-confirmation :id="'delete-destination-'.$destination->id" :route="route('infrastructure.backups.destinations.destroy', [$project, $destination->id])" :title="__('Delete :destination?', ['destination' => $destination->name])" :description="__('The bucket and anything in it stay as they are.')" :submit-label="__('Delete destination')" />
                    @endif
                </div>
            @empty
                <p class="text-sm text-muted">{{ __('No destinations yet.') }}</p>
            @endforelse

            @if ($canManage)
                <div><x-signal.ui.button :href="request()->fullUrlWithQuery(['dialog' => 'add-backup-destination'])" :variant="$destinations->isEmpty() ? 'primary' : 'secondary'" data-modal-trigger="add-backup-destination">{{ __('Add a destination') }}</x-signal.ui.button></div>
                <x-signal.overlays.modal id="add-backup-destination" :title="__('Add a backup destination')" :description="__('S3-compatible storage you own. We check we can write to it before saving.')">
                <form method="POST" action="{{ route('infrastructure.backups.destinations.store', $project) }}" class="grid items-start gap-4 sm:grid-cols-2">
                    @csrf
                    <input type="hidden" name="_modal" value="add-backup-destination">
                    @include('infrastructure._backup-destination-fields', ['destination' => null])
                    <ul class="grid gap-1 text-xs text-muted sm:col-span-2">
                        @foreach ($presets as $preset)
                            <li><span class="font-bold">{{ $preset['name'] }}:</span> {{ $preset['description'] }} <span class="font-mono">{{ $preset['endpoint'] }}</span></li>
                        @endforeach
                    </ul>
                    <div class="flex justify-end sm:col-span-2"><x-signal.ui.button type="submit" variant="primary">{{ __('Add destination') }}</x-signal.ui.button></div>
                </form>
                </x-signal.overlays.modal>
            @endif
        </div>
    </x-signal.ui.settings-section>

    <x-signal.ui.settings-section :title="__('Recent backups')" :description="__('Run, schedule, restore and verify backups on each website’s page.')">
        @if ($backups->isEmpty())
            <p class="p-4 text-sm text-muted sm:p-6">{{ __('No backups yet.') }}</p>
        @else
            <x-signal.ui.table :caption="__('Recent backups')">
                <x-slot:head><tr><th scope="col">{{ __('Website') }}</th><th scope="col">{{ __('Started') }}</th><th scope="col">{{ __('Destination') }}</th><th scope="col">{{ __('Size') }}</th><th scope="col">{{ __('Status') }}</th></tr></x-slot:head>
                @foreach ($backups as $backup)
                    <tr>
                        <td>@if ($backup->website->trashed()){{ $backup->website->name }}@else<a href="{{ route('infrastructure.websites.show', [$project, $backup->website_id]) }}" class="font-bold text-primary hover:underline">{{ $backup->website->name }}</a>@endif</td>
                        <td class="text-muted">{{ $backup->created_at?->toDayDateTimeString() }}</td>
                        <td>{{ $backup->destination->name }}</td>
                        <td class="text-muted">{{ $backup->size_bytes !== null ? \Illuminate\Support\Number::fileSize($backup->size_bytes, maxPrecision: 1) : '—' }}</td>
                        <td>@include('infrastructure._backup-status', ['status' => $backup->status])</td>
                    </tr>
                @endforeach
            </x-signal.ui.table>
        @endif
    </x-signal.ui.settings-section>
</x-signal.layouts.project>
