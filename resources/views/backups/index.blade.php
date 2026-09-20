<x-layouts.app>
    @php
        $scheduleHasErrors = $errors->hasAny([
            'website_id', 'backup_destination_id', 'frequency', 'weekday', 'run_at', 'retention_count',
        ]);
        $scheduleDialogOpen = (request()->query('dialog') === 'add-schedule' && ! session()->has('success'))
            || (old('_backup_schedule_form') === '1' && $scheduleHasErrors);
        $scheduleDialogUrl = route('backups.index', ['dialog' => 'add-schedule']);
        $destinationHasErrors = $errors->hasAny([
            'storage_provider', 'name', 'endpoint', 'bucket', 'region', 'access_key', 'secret_key', 'path_prefix',
        ]);
        $destinationDialog = request()->query('dialog');
        $destinationCreateOpen = ($destinationDialog === 'add-destination' && ! session()->has('success'))
            || (old('_backup_destination_form') === 'create' && $destinationHasErrors);
        $destinationCreateUrl = route('backups.index', ['dialog' => 'add-destination']);
        $editingDestination = null;
        if (is_string($destinationDialog) && preg_match('/\Aedit-destination-(\d+)\z/', $destinationDialog, $matches) === 1) {
            $editingDestination = $destinations->firstWhere('id', (int) $matches[1]);
        }
        $destinationEditOpen = $editingDestination instanceof \App\Models\BackupDestination
            && (($destinationDialog === 'edit-destination-'.$editingDestination->id && ! session()->has('success'))
                || (old('_backup_destination_form') === 'edit'
                    && (string) old('_backup_destination_id') === (string) $editingDestination->id
                    && $destinationHasErrors));
    @endphp

    <x-layouts.partials.heading
        eyebrow="{{ __('Recovery') }}"
        icon="database"
        :title="__('Managed backups')"
        :description="__('Encrypted, offsite restic snapshots of site databases, persistent storage, and environment configuration.')"
    >
        @if ($canManage && $destinations->isNotEmpty() && $websites->isNotEmpty())
            <x-slot:buttons>
                <x-ui.button
                    href="{{ $scheduleDialogUrl }}"
                    data-modal-trigger="backup-schedule-dialog"
                    aria-controls="backup-schedule-dialog"
                    aria-expanded="{{ $scheduleDialogOpen ? 'true' : 'false' }}"
                    variant="primary"
                >
                    {{ __('Add schedule') }}
                </x-ui.button>
            </x-slot:buttons>
        @endif
    </x-layouts.partials.heading>

    @php
        $hasDestination = $destinations->isNotEmpty();
        $hasCompletedBackup = $recoverySummary->latestBackupCompletedAt !== null;
        $hasIndependentVerification = $recoverySummary->latestIndependentRecoveryVerificationAt !== null;
        $readiness = match (true) {
            ! $hasDestination => [
                'tone' => 'warning',
                'label' => __('Setup required'),
                'title' => __('Add a protected destination first'),
                'description' => __('Backups cannot start until an encrypted offsite destination is configured and verified.'),
                'action' => __('Add destination'),
                'target' => '#backup-destinations',
            ],
            ! $hasCompletedBackup => [
                'tone' => 'warning',
                'label' => __('Ready to protect'),
                'title' => __('Run the first backup'),
                'description' => __('A destination is configured, but no completed backup has been recorded for this workspace yet.'),
                'action' => __('Run a backup'),
                'target' => '#backup-history',
            ],
            ! $hasIndependentVerification => [
                'tone' => 'accent',
                'label' => __('Verification recommended'),
                'title' => __('Test recovery from a completed backup'),
                'description' => __('Backups are completing, but an isolated restore verification has not been recorded yet.'),
                'action' => __('Review recovery actions'),
                'target' => '#backup-history',
            ],
            default => [
                'tone' => 'success',
                'label' => __('Recovery evidence current'),
                'title' => __('Backups and independent recovery checks are recorded'),
                'description' => __('Review the latest evidence below or open history to inspect a specific website backup.'),
                'action' => __('Review evidence'),
                'target' => '#backup-recovery-evidence',
            ],
        };
    @endphp

    <section id="backup-readiness" class="ui-card mt-6 scroll-mt-24 border-ternary p-5" aria-labelledby="backup-readiness-title" data-backup-readiness>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Protection status') }}</p>
                <h2 id="backup-readiness-title" class="mt-1 text-xl font-black text-primary">{{ $readiness['title'] }}</h2>
                <p class="mt-1 max-w-3xl text-sm leading-6 text-secondary">{{ $readiness['description'] }}</p>
            </div>
            <x-ui.badge :tone="$readiness['tone']">{{ $readiness['label'] }}</x-ui.badge>
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <x-ui.button :href="$readiness['target']" variant="primary">{{ $readiness['action'] }}</x-ui.button>
            <x-ui.button href="#backup-destinations" variant="secondary">{{ __('Destinations and schedules') }}</x-ui.button>
        </div>
    </section>

    <x-ui.insights
        id="backup-recovery-evidence"
        class="mt-6 scroll-mt-24"
        :summary="__('Completion, restore and verification history')"
        data-responsive-details-mobile-open="false"
        aria-label="{{ __('Recovery evidence') }}"
    >
        <dl class="ui-insight-grid grid gap-3 sm:grid-cols-2 xl:grid-cols-5" aria-label="{{ __('Recovery readiness') }}">
                @foreach ([
                    [__('Latest completed backup'), $recoverySummary->latestBackupCompletedAt?->diffForHumans() ?? __('No completed backup')],
                    [__('Latest HTTPS transport evidence'), $recoverySummary->latestTransportVerifiedAt?->diffForHumans() ?? __('Not recorded')],
                    [__('Latest in-place restore'), $recoverySummary->latestRestoreCompletedAt?->diffForHumans() ?? __('No completed restore')],
                    [__('Independent restore verification'), $recoverySummary->latestIndependentRecoveryVerificationAt?->diffForHumans() ?? __('Not recorded')],
                    [__('Observed restore time'), $recoverySummary->latestRestoreSeconds === null ? __('Not measured') : trans_choice(':count second|:count seconds', $recoverySummary->latestRestoreSeconds, ['count' => $recoverySummary->latestRestoreSeconds])],
                ] as [$label, $value])
                    <x-ui.stat :label="$label" :value="$value" />
                @endforeach
        </dl>
    </x-ui.insights>

    <div class="mt-6 grid gap-5 xl:grid-cols-2">
        <section id="backup-destinations" class="ui-card scroll-mt-24 p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-black text-primary">{{ __('Destinations') }}</h2>
                    <p class="mt-1 text-sm text-secondary">{{ __('S3, R2, Spaces, and MinIO credentials stay encrypted at rest.') }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <x-ui.badge>{{ $destinations->count() }}</x-ui.badge>
                    @if ($canManage)
                        <x-ui.button
                            :href="$destinationCreateUrl"
                            data-modal-trigger="backup-destination-create-dialog"
                            aria-controls="backup-destination-create-dialog"
                            aria-expanded="{{ $destinationCreateOpen ? 'true' : 'false' }}"
                            variant="secondary"
                        >
                            {{ __('Add destination') }}
                        </x-ui.button>
                    @endif
                </div>
            </div>

            <div class="mt-5 space-y-3">
                @forelse ($destinations as $destination)
                    <article class="rounded-xl border border-primary bg-secondary p-4">
                        <div class="flex items-start gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="font-bold text-primary">{{ $destination->name }}</p>
                                <p class="break-all text-xs text-secondary">
                                    {{ $destination->bucket }}/{{ $destination->path_prefix }} · {{ $destination->last_verified_at?->diffForHumans() ?? __('not verified yet') }}
                                </p>
                                @if ($destination->last_error)
                                    <p class="ui-alert ui-alert--danger mt-2 text-xs">{{ $destination->last_error }}</p>
                                @endif
                            </div>
                            @if ($canManage)
                                <form method="POST" action="{{ route('backups.destinations.destroy', $destination) }}" class="shrink-0">
                                    @csrf
                                    @method('DELETE')
                                    <x-ui.button type="submit" variant="danger">{{ __('Delete') }}</x-ui.button>
                                </form>
                            @endif
                        </div>

                        @if ($canManage)
                            <div class="mt-4 flex flex-wrap gap-2">
                                @php
                                    $destinationEditDialogId = 'backup-destination-edit-'.$destination->id;
                                    $destinationEditUrl = route('backups.index', ['dialog' => 'edit-destination-'.$destination->id]);
                                    $destinationEditContentUrl = route('backups.destinations.edit', ['destination' => $destination, 'return_to' => request()->fullUrlWithoutQuery('dialog')]);
                                @endphp
                                <x-ui.button
                                    :href="$destinationEditUrl"
                                    data-modal-trigger="{{ $destinationEditDialogId }}"
                                    data-modal-content-url="{{ $destinationEditContentUrl }}"
                                    aria-controls="{{ $destinationEditDialogId }}"
                                    aria-expanded="{{ $destinationEditOpen && $editingDestination?->is($destination) ? 'true' : 'false' }}"
                                    variant="secondary"
                                >
                                    {{ __('Edit connection') }}
                                </x-ui.button>
                                <details class="min-w-52 flex-1 rounded-lg border border-primary bg-primary px-3 py-2">
                                    <summary class="cursor-pointer text-sm font-bold text-primary">{{ __('Verify connection') }}</summary>
                                    <div class="mt-3 space-y-3">
                                        <p class="text-xs leading-5 text-secondary">{{ __('BuildPusher writes, reads, and deletes a temporary object over HTTPS. No active website or server is required.') }}</p>
                                        <form method="POST" action="{{ route('backups.destinations.test', $destination) }}">
                                            @csrf
                                            <x-ui.button type="submit" variant="secondary">{{ __('Verify') }}</x-ui.button>
                                        </form>
                                    </div>
                                </details>
                                <x-scenes.backups.destination-edit-dialog
                                    :destination="$destination"
                                    :destination-catalog="$destinationCatalog"
                                    :destination-presets="$destinationPresets"
                                    :open="$destinationEditOpen && $editingDestination?->is($destination)"
                                    :content-url="$destinationEditContentUrl"
                                    :cancel-url="request()->fullUrlWithoutQuery('dialog')"
                                />
                            </div>
                        @endif
                    </article>
                @empty
                    <x-ui.empty-state
                        :title="__('No backup destinations')"
                        :description="__('Add an offsite destination to begin.')"
                        icon="database"
                    />
                @endforelse
            </div>

            @if ($canManage)
                <x-scenes.backups.destination-create-dialog
                    :destination-catalog="$destinationCatalog"
                    :destination-presets="$destinationPresets"
                    :open="$destinationCreateOpen"
                />
            @endif
        </section>

        <section id="backup-schedules" class="ui-card scroll-mt-24 p-6">
            @php
                $scheduleCount = $websites->sum(fn ($website) => $website->backupSchedules->count());
            @endphp
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-black text-primary">{{ __('Schedules') }}</h2>
                    <p class="mt-1 text-sm text-secondary">{{ __('Automate retention without managing cron jobs.') }}</p>
                </div>
                <x-ui.badge>{{ $scheduleCount }}</x-ui.badge>
            </div>

            <div class="mt-5 space-y-3">
                @forelse ($websites->flatMap->backupSchedules as $schedule)
                    <div class="flex items-center gap-3 rounded-xl border border-primary bg-secondary p-4">
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-bold text-primary">{{ $schedule->website->name }}</p>
                            <p class="text-xs text-secondary">{{ ucfirst($schedule->frequency) }} at {{ substr($schedule->run_at, 0, 5) }} UTC · keep {{ $schedule->retention_count }} · {{ $schedule->destination->name }}</p>
                        </div>
                        @if ($canManage)
                            <form method="POST" action="{{ route('backups.schedules.destroy', $schedule) }}" class="shrink-0">
                                @csrf
                                @method('DELETE')
                                <x-ui.button type="submit" variant="danger">{{ __('Delete') }}</x-ui.button>
                            </form>
                        @endif
                    </div>
                @empty
                    <x-ui.empty-state
                        :title="__('No recurring schedules')"
                        :description="__('No recurring schedules yet.')"
                        icon="clock"
                    />
                @endforelse
            </div>

            @if ($canManage && $destinations->isNotEmpty() && $websites->isNotEmpty())
                <x-scenes.backups.schedule-dialog
                    :websites="$websites"
                    :destinations="$destinations"
                    :open="$scheduleDialogOpen"
                />
            @endif
        </section>
    </div>

    <section id="backup-history" class="ui-card mt-6 scroll-mt-24 overflow-hidden">
        <div class="flex flex-wrap items-start justify-between gap-4 p-6">
            <div>
                <h2 class="text-xl font-black text-primary">{{ __('Backup history and restore') }}</h2>
                <p class="mt-1 max-w-4xl text-sm leading-6 text-secondary">{{ __('In-place restores create a safety snapshot, verify health, and roll back automatically on failure. Isolated verification uses temporary targets and never overwrites live data.') }}</p>
            </div>
            @if ($canManage && $destinations->isNotEmpty() && $websites->isNotEmpty())
                <details class="rounded-xl border border-primary bg-secondary px-4 py-2">
                    <summary class="cursor-pointer text-sm font-bold text-primary">{{ __('Run backup') }}</summary>
                    <div class="mt-3 w-72 max-w-[calc(100vw-3rem)] space-y-2">
                        @foreach ($websites as $website)
                            <form method="POST" action="{{ route('backups.run', $website) }}" class="rounded-lg border border-primary bg-primary p-3">
                                @csrf
                                <p class="mb-2 truncate text-sm font-bold text-primary">{{ $website->name }}</p>
                                <div class="flex gap-2">
                                    <label class="sr-only" for="backup-destination-{{ $website->id }}">{{ __('Backup destination') }}</label>
                                    <select id="backup-destination-{{ $website->id }}" name="backup_destination_id" class="input secondary min-w-0 flex-1 rounded-md">
                                        @foreach ($destinations as $destination)
                                            <option value="{{ $destination->id }}">{{ $destination->name }}</option>
                                        @endforeach
                                    </select>
                                    <x-ui.button type="submit" variant="primary">{{ __('Run') }}</x-ui.button>
                                </div>
                            </form>
                        @endforeach
                    </div>
                </details>
            @endif
        </div>

        <div id="backup-history-list" class="space-y-3 p-4 sm:p-6" aria-label="{{ __('Backup history') }}">
            @forelse ($backups as $backup)
                @php
                    $verification = $backup->verifications->sortByDesc('id')->first();
                    $canVerify = $canManage && $backup->status === \App\Models\WebsiteBackup::STATUS_SUCCEEDED;
                    $backupTone = match ($backup->status) {
                        \App\Models\WebsiteBackup::STATUS_SUCCEEDED => 'success',
                        \App\Models\WebsiteBackup::STATUS_FAILED => 'danger',
                        default => 'accent',
                    };
                @endphp
                @include('backups._mobile-backup-card', compact('backup', 'verification', 'canVerify', 'backupTone'))
            @empty
                <x-ui.empty-state
                    :title="__('No backups have run yet.')"
                    :description="__('Run a backup after configuring an offsite destination.')"
                    icon="database"
                />
            @endforelse
        </div>
    </section>
</x-layouts.app>
