{{-- The Backups page: a warning when backups stay on this server, then the recent backups. --}}
<x-filament-panels::page>
    @unless ($offsite)
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="warning" :heading="__('Backups only live on this server')">
            <p class="text-sm">{{ __('Losing the server loses them too. Set PLATFORM_BACKUP_S3_ENDPOINT, _BUCKET, _KEY and _SECRET (any S3-compatible storage) to copy each one off-site.') }}</p>
        </x-filament::section>
    @endunless
    {{ $this->table }}
</x-filament-panels::page>
