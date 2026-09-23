<div @if ($shouldPoll) wire:poll.5s @endif class="ui-console my-4 p-5 font-mono text-sm leading-5">
    <div class="flex items-center justify-between gap-3">
        <div>
            <p class="font-sans text-sm font-semibold text-emphasis-ink">{{ __('Provisioning output') }}</p>
            <p class="font-sans text-xs text-emphasis-muted">
                @if ($updatedAt)
                    {{ __('Updated :time', ['time' => $updatedAt->diffForHumans()]) }}
                @elseif ($shouldPoll)
                    {{ __('Waiting for remote output…') }}
                @else
                    {{ __('No remote output was received.') }}
                @endif
            </p>
        </div>
        <div class="flex items-center gap-3 font-sans">
            @if ($hasLog)
                <a href="{{ route('websites.provisioning-log.download', $website) }}" class="ui-link font-sans text-xs">
                    {{ __('Download log') }}
                </a>
            @endif
            <span class="font-sans text-xs uppercase text-emphasis-muted">{{ $website->provisioning_status }}</span>
        </div>
    </div>

    <div class="mt-4 flex max-h-96 flex-col overflow-y-auto">
        @forelse ($lines as $line)
            @if ($line === '') @continue @endif
            <div class="w-full">
                <span class="text-emphasis-ink">{{ $website->deployment_slug }}:~$</span>
                <span class="text-emphasis-ink">{{ $line }}</span>
            </div>
        @empty
            <div class="text-emphasis-muted">
                {{ $shouldPoll ? __('Waiting for website provisioning output…') : __('No website provisioning output to show.') }}
            </div>
        @endforelse
    </div>
</div>
