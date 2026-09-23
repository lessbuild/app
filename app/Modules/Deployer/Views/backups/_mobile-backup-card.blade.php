<details id="backup-mobile-{{ $backup->id }}" class="ui-panel group" @if ($errors->has('confirmation')) open @endif>
    <summary class="flex cursor-pointer list-none items-start justify-between gap-3 p-4 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus">
        <span class="min-w-0 flex-1">
            <span class="flex flex-wrap items-center gap-2">
                <span class="truncate font-bold text-ink">{{ $backup->website->name }}</span>
                <x-ui.badge :tone="$backupTone">{{ ucfirst($backup->status) }}</x-ui.badge>
            </span>
            <span class="mt-1 block text-xs text-muted">{{ $backup->completed_at?->diffForHumans() ?? __('Not completed') }}</span>
        </span>
        <span class="shrink-0 text-xl text-muted transition-transform group-open:rotate-45" aria-hidden="true">+</span>
    </summary>

    <div class="space-y-4 border-t border-line p-4">
        <dl class="grid gap-3 text-sm sm:grid-cols-2">
            <div>
                <dt class="ui-eyebrow text-[0.65rem]">{{ __('Snapshot') }}</dt>
                <dd class="mt-1 break-all font-mono text-xs text-ink">{{ $backup->snapshot_id ?: '—' }}</dd>
            </div>
            <div>
                <dt class="ui-eyebrow text-[0.65rem]">{{ __('Completed') }}</dt>
                <dd class="mt-1 text-ink">{{ $backup->completed_at?->diffForHumans() ?? '—' }}</dd>
            </div>
        </dl>

        @if ($backup->error)
            <p class="ui-alert ui-alert--danger text-xs">{{ $backup->error }}</p>
        @endif

        <section class="ui-panel p-3" aria-labelledby="backup-mobile-verification-{{ $backup->id }}">
            <h3 id="backup-mobile-verification-{{ $backup->id }}" class="text-sm font-bold text-ink">{{ __('Verification') }}</h3>
            @if ($verification)
                @php
                    $verificationTone = match ($verification->status) {
                        \App\Modules\Deployer\Models\BackupRestoreVerification::STATUS_SUCCEEDED => 'success',
                        \App\Modules\Deployer\Models\BackupRestoreVerification::STATUS_FAILED => 'danger',
                        default => 'accent',
                    };
                @endphp
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <x-ui.badge :tone="$verificationTone">{{ ucfirst($verification->status) }}</x-ui.badge>
                    <span class="text-xs text-muted">{{ ucfirst($verification->integrity_status) }} integrity</span>
                    <span class="text-xs text-muted">{{ ucfirst($verification->smoke_status) }} smoke</span>
                    <span class="text-xs text-muted">{{ ucfirst($verification->cleanup_status) }} cleanup</span>
                </div>
                @if ($verification->failure_stage)
                    <p class="mt-2 text-xs text-danger">{{ __('Failed at :stage', ['stage' => $verification->failure_stage]) }}</p>
                @endif
                @if ($canVerify && $verification->status === \App\Modules\Deployer\Models\BackupRestoreVerification::STATUS_FAILED)
                    <form method="POST" action="{{ route('backups.verify', $backup) }}" class="mt-3 space-y-2">
                        @csrf
                        <label class="sr-only" for="mobile-retry-verification-{{ $backup->id }}">{{ __('Confirmation') }}</label>
                        <input id="mobile-retry-verification-{{ $backup->id }}" name="confirmation" placeholder="{{ $backup->website->name }}" class="ui-input" required>
                        <x-ui.button type="submit" variant="secondary">{{ __('Retry verification') }}</x-ui.button>
                    </form>
                @endif
            @elseif ($canVerify)
                <form method="POST" action="{{ route('backups.verify', $backup) }}" class="mt-3 space-y-2">
                    @csrf
                    <label class="sr-only" for="mobile-verification-{{ $backup->id }}">{{ __('Confirmation') }}</label>
                    <input id="mobile-verification-{{ $backup->id }}" name="confirmation" placeholder="{{ $backup->website->name }}" class="ui-input" required>
                    <x-ui.button type="submit" variant="secondary">{{ __('Verify safely') }}</x-ui.button>
                    <span class="block text-xs text-muted">{{ __('Temporary database and storage only; no live overwrite.') }}</span>
                </form>
            @else
                <p class="mt-2 text-sm text-muted">{{ __('Verification is available after a successful backup.') }}</p>
            @endif
        </section>

        <section class="ui-panel p-3" aria-labelledby="backup-mobile-restore-{{ $backup->id }}">
            <h3 id="backup-mobile-restore-{{ $backup->id }}" class="text-sm font-bold text-ink">{{ __('Restore') }}</h3>
            @if ($canVerify)
                <form method="POST" action="{{ route('backups.restore', $backup) }}" class="mt-3 space-y-2">
                    @csrf
                    <label class="sr-only" for="mobile-restore-{{ $backup->id }}">{{ __('Confirmation') }}</label>
                    <input id="mobile-restore-{{ $backup->id }}" name="confirmation" placeholder="{{ $backup->website->name }}" class="ui-input" required>
                    <x-ui.button type="submit" variant="danger" onclick="return confirm({{ Illuminate\Support\Js::from(__('Restore this backup and replace the current database and persistent files?')) }})">{{ __('Restore') }}</x-ui.button>
                </form>
            @else
                <p class="mt-2 text-sm text-muted">{{ __('Restore is available after a successful backup.') }}</p>
            @endif
        </section>
    </div>
</details>
