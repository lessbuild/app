<div @if ($shouldPoll) wire:poll.5s @endif class="my-4 coding inverse-toggle px-5 py-4 shadow-lg text-primary text-sm font-mono subpixel-antialiased bg-primary rounded-lg leading-normal overflow-hidden border border-primary">
    <div class="flex items-center justify-between gap-3">
        <div>
            <p class="font-sans text-sm font-semibold text-primary">{{ __('Provisioning output') }}</p>
            <p class="font-sans text-xs text-secondary">
                @if ($updatedAt)
                    {{ __('Updated :time', ['time' => $updatedAt->diffForHumans()]) }}
                @elseif ($shouldPoll)
                    {{ __('Waiting for remote output…') }}
                @else
                    {{ __('No remote output was received.') }}
                @endif
            </p>
        </div>
        <span class="font-sans text-xs uppercase text-secondary">{{ $website->provisioning_status }}</span>
    </div>

    <div class="mt-4 flex max-h-96 flex-col overflow-y-auto">
        @forelse ($lines as $line)
            @if ($line === '') @continue @endif
            <div class="w-full">
                <span class="text-ternary">{{ $website->deployment_slug }}:~$</span>
                <span class="text-primary">{{ $line }}</span>
            </div>
        @empty
            <div class="text-secondary">
                {{ $shouldPoll ? __('Waiting for website provisioning output…') : __('No website provisioning output to show.') }}
            </div>
        @endforelse
    </div>
</div>
