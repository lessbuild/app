<x-layouts.app>
    <x-layouts.partials.heading icon="database" :title="__('Managed backups')" :description="__('Encrypted, offsite restic snapshots of site databases, persistent storage, and environment configuration.')" />

    <section class="mt-8 grid gap-3 sm:grid-cols-3" aria-label="{{ __('Recovery readiness') }}">@foreach([[__('Latest recovery point'),$recoveryMetrics['last_recovery_point']?->diffForHumans() ?? __('No verified backup')],[__('Latest restore evidence'),$recoveryMetrics['last_restore_drill']?->diffForHumans() ?? __('No completed restore')],[__('Observed restore time'),$recoveryMetrics['last_restore_seconds'] === null ? __('Not measured') : trans_choice(':count second|:count seconds',$recoveryMetrics['last_restore_seconds'],['count'=>$recoveryMetrics['last_restore_seconds']])]] as [$label,$value])<div class="rounded-2xl border border-primary bg-primary p-4"><p class="text-xs font-bold uppercase text-secondary">{{ $label }}</p><p class="mt-2 font-black text-primary">{{ $value }}</p></div>@endforeach</section>

    <div class="mt-5 grid gap-5 xl:grid-cols-2">
        <section class="rounded-2xl border border-primary bg-primary p-6">
            <div class="flex items-start justify-between gap-4"><div><h2 class="text-xl font-black text-primary">{{ __('Destinations') }}</h2><p class="mt-1 text-sm text-secondary">{{ __('S3, R2, Spaces, and MinIO credentials stay encrypted at rest.') }}</p></div><span class="rounded-full bg-secondary px-2.5 py-1 text-xs font-bold text-secondary">{{ $destinations->count() }}</span></div>
            <div class="mt-4 space-y-3">
                @forelse($destinations as $destination)
                    <div class="flex items-center gap-3 rounded-xl border border-primary bg-secondary p-4"><div class="min-w-0 flex-1"><p class="font-bold text-primary">{{ $destination->name }}</p><p class="truncate text-xs text-secondary">{{ $destination->bucket }}/{{ $destination->path_prefix }} · {{ $destination->last_verified_at?->diffForHumans() ?? __('not verified yet') }}</p>@if($destination->last_error)<p class="mt-1 text-xs text-red-700">{{ $destination->last_error }}</p>@endif</div>@if($canManage)<form method="POST" action="{{ route('backups.destinations.destroy', $destination) }}">@csrf @method('DELETE')<button type="submit" class="button tertiary">{{ __('Delete') }}</button></form>@endif</div>
                @empty
                    <div class="rounded-xl border border-dashed border-primary p-4 text-sm text-secondary">{{ __('Add an offsite destination to begin.') }}</div>
                @endforelse
            </div>
            @if($canManage)
                <details class="mt-4 rounded-xl border border-primary bg-secondary p-4"><summary class="cursor-pointer font-bold text-primary">{{ __('Add destination') }}</summary>
                    <form method="POST" action="{{ route('backups.destinations.store') }}" class="mt-4 grid gap-3 sm:grid-cols-2">@csrf
                        <input name="name" placeholder="Offsite backups" class="input secondary rounded" required><input type="url" name="endpoint" placeholder="https://s3.example.com" class="input secondary rounded" required><input name="bucket" placeholder="my-backups" class="input secondary rounded" required><input name="region" value="us-east-1" class="input secondary rounded" required><input name="access_key" placeholder="Access key" autocomplete="off" class="input secondary rounded" required><input type="password" name="secret_key" placeholder="Secret key" autocomplete="new-password" class="input secondary rounded" required><input name="path_prefix" value="buildpusher" class="input secondary rounded sm:col-span-2" required><button type="submit" class="button primary sm:col-span-2">{{ __('Create encrypted destination') }}</button>
                    </form>
                </details>
            @endif
        </section>

        <section class="rounded-2xl border border-primary bg-primary p-6">
            <div class="flex items-start justify-between gap-4"><div><h2 class="text-xl font-black text-primary">{{ __('Schedules') }}</h2><p class="mt-1 text-sm text-secondary">{{ __('Automate retention without managing cron jobs.') }}</p></div><span class="rounded-full bg-secondary px-2.5 py-1 text-xs font-bold text-secondary">{{ $websites->sum(fn ($website) => $website->backupSchedules->count()) }}</span></div>
            <div class="mt-4 space-y-3">
                @forelse($websites->flatMap->backupSchedules as $schedule)
                    <div class="flex items-center gap-3 rounded-xl border border-primary bg-secondary p-4"><div class="flex-1"><p class="font-bold text-primary">{{ $schedule->website->name }}</p><p class="text-xs text-secondary">{{ ucfirst($schedule->frequency) }} at {{ substr($schedule->run_at, 0, 5) }} UTC · keep {{ $schedule->retention_count }} · {{ $schedule->destination->name }}</p></div>@if($canManage)<form method="POST" action="{{ route('backups.schedules.destroy', $schedule) }}">@csrf @method('DELETE')<button type="submit" class="button tertiary">{{ __('Delete') }}</button></form>@endif</div>
                @empty
                    <div class="rounded-xl border border-dashed border-primary p-4 text-sm text-secondary">{{ __('No recurring schedules yet.') }}</div>
                @endforelse
            </div>
            @if($canManage && $destinations->isNotEmpty() && $websites->isNotEmpty())
                <details class="mt-4 rounded-xl border border-primary bg-secondary p-4"><summary class="cursor-pointer font-bold text-primary">{{ __('Add schedule') }}</summary>
                    <form method="POST" action="{{ route('backups.schedules.store') }}" class="mt-4 grid gap-3 sm:grid-cols-2">@csrf
                        <select name="website_id" class="input secondary rounded">@foreach($websites as $website)<option value="{{ $website->id }}">{{ $website->name }}</option>@endforeach</select><select name="backup_destination_id" class="input secondary rounded">@foreach($destinations as $destination)<option value="{{ $destination->id }}">{{ $destination->name }}</option>@endforeach</select><select name="frequency" class="input secondary rounded"><option value="daily">{{ __('Daily') }}</option><option value="weekly">{{ __('Weekly') }}</option></select><input type="time" name="run_at" value="02:00" class="input secondary rounded"><input type="number" name="weekday" min="0" max="6" value="0" class="input secondary rounded" title="{{ __('Sunday is 0') }}"><input type="number" name="retention_count" min="1" max="365" value="14" class="input secondary rounded"><button type="submit" class="button primary sm:col-span-2">{{ __('Save schedule') }}</button>
                    </form>
                </details>
            @endif
        </section>
    </div>

    <section class="mt-5 rounded-2xl border border-primary bg-primary p-6">
        <div class="flex flex-wrap items-start justify-between gap-4"><div><h2 class="text-xl font-black text-primary">{{ __('Backup history and restore') }}</h2><p class="mt-1 text-sm text-secondary">{{ __('Restores create a safety snapshot, verify health, and roll back automatically on failure.') }}</p></div>
            @if($canManage && $destinations->isNotEmpty() && $websites->isNotEmpty())
                <details class="rounded-xl border border-primary bg-secondary px-4 py-2"><summary class="cursor-pointer text-sm font-bold text-primary">{{ __('Run backup') }}</summary><div class="mt-3 w-72 space-y-2">@foreach($websites as $website)<form method="POST" action="{{ route('backups.run', $website) }}" class="rounded-lg border border-primary bg-primary p-3">@csrf<p class="mb-2 truncate text-sm font-bold text-primary">{{ $website->name }}</p><div class="flex gap-2"><select name="backup_destination_id" class="input secondary min-w-0 flex-1 rounded">@foreach($destinations as $destination)<option value="{{ $destination->id }}">{{ $destination->name }}</option>@endforeach</select><button type="submit" class="button primary">{{ __('Run') }}</button></div></form>@endforeach</div></details>
            @endif
        </div>
        <div class="mt-5 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b border-primary text-xs uppercase text-secondary"><th class="p-3">{{ __('Website') }}</th><th class="p-3">{{ __('Status') }}</th><th class="p-3">{{ __('Snapshot') }}</th><th class="p-3">{{ __('Completed') }}</th><th class="p-3">{{ __('Restore') }}</th></tr></thead><tbody>
            @forelse($backups as $backup)
                <tr class="border-b border-primary"><td class="p-3 font-medium text-primary">{{ $backup->website->name }}</td><td class="p-3">{{ ucfirst($backup->status) }}@if($backup->error)<span class="block max-w-xs text-xs text-red-700">{{ $backup->error }}</span>@endif</td><td class="p-3 font-mono text-xs">{{ $backup->snapshot_id ? substr($backup->snapshot_id, 0, 12) : '—' }}</td><td class="p-3 text-secondary">{{ $backup->completed_at?->diffForHumans() ?? '—' }}</td><td class="p-3">@if($canManage && $backup->status === \App\Models\WebsiteBackup::STATUS_SUCCEEDED)<form method="POST" action="{{ route('backups.restore', $backup) }}" class="flex gap-2">@csrf<input name="confirmation" placeholder="{{ $backup->website->name }}" class="input secondary max-w-44 rounded" required><button type="submit" class="button secondary" onclick="return confirm({{ Illuminate\Support\Js::from(__('Restore this backup and replace the current database and persistent files?')) }})">{{ __('Restore') }}</button></form>@else — @endif</td></tr>
            @empty
                <tr><td colspan="5" class="p-6 text-center text-secondary">{{ __('No backups have run yet.') }}</td></tr>
            @endforelse
        </tbody></table></div>
    </section>
</x-layouts.app>
