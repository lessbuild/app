<x-signal.layouts.admin :title="__('Backups')" :description="__('Copies of the platform’s own database, taken every night at 02:30 UTC.')">
    @if (session('error'))<x-signal.ui.alert tone="danger" role="alert">{{ session('error') }}</x-signal.ui.alert>@endif
    @unless ($offsite)
        <x-signal.ui.alert tone="warning" role="alert">{{ __('Backups are only kept on this server, so losing the server loses them too. Set PLATFORM_BACKUP_S3_ENDPOINT, _BUCKET, _KEY and _SECRET (any S3-compatible storage) to copy each one off-site.') }}</x-signal.ui.alert>
    @endunless

    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-muted">{{ $latest ? __('Last good backup :when.', ['when' => $latest->created_at?->diffForHumans()]) : __('No backup yet.') }}</p>
        <form method="POST" action="{{ route('admin.backups.store') }}">@csrf<x-signal.ui.button type="submit" variant="primary">{{ __('Back up now') }}</x-signal.ui.button></form>
    </div>

    <x-signal.ui.table :caption="__('Recent backups')">
        <x-slot:head><tr><th scope="col">{{ __('When') }}</th><th scope="col">{{ __('File') }}</th><th scope="col">{{ __('Size') }}</th><th scope="col">{{ __('Kept') }}</th><th scope="col">{{ __('Status') }}</th></tr></x-slot:head>
        @forelse ($backups as $backup)
            <tr>
                <td class="whitespace-nowrap">{{ $backup->created_at?->diffForHumans() }} <span class="text-xs text-muted">· {{ __(ucfirst($backup->trigger)) }}</span></td>
                <td class="font-mono text-xs">{{ $backup->file }}</td>
                <td>{{ $backup->size !== null ? \Illuminate\Support\Number::fileSize($backup->size, 1) : '—' }}</td>
                <td>{{ collect([$backup->isLocal() ? __('this server') : null, $backup->isOffsite() ? __('off-site') : null])->filter()->implode(' + ') ?: '—' }}</td>
                <td>
                    <x-signal.ui.badge :tone="$backup->succeeded() ? ($backup->error ? 'warning' : 'success') : 'danger'">{{ $backup->succeeded() ? __('Done') : __('Failed') }}</x-signal.ui.badge>
                    @if ($backup->error)<p class="mt-1 text-xs text-muted">{{ $backup->error }}</p>@endif
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="text-muted">{{ __('No backups yet. The first runs tonight, or back up now.') }}</td></tr>
        @endforelse
    </x-signal.ui.table>

    <x-signal.ui.settings-section :title="__('Restoring')" :description="__('Done on the server, so the app can be stopped first.')">
        <div class="grid gap-3 p-4 text-sm sm:p-6">
            <p class="text-muted">{{ __('Stop the queue workers and put the site in maintenance mode, then restore by backup id. The current database is kept beside the restored one.') }}</p>
            <x-signal.ui.code-block code="php artisan down
php artisan platform:backups
php artisan platform:restore <id>
php artisan migrate --force
php artisan up" />
        </div>
    </x-signal.ui.settings-section>
</x-signal.layouts.admin>
