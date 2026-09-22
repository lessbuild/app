<div @if ($shouldPoll) wire:poll.5s @endif class="my-4 overflow-hidden rounded-xl border border-slate-800 bg-slate-950 p-5 text-sm text-slate-100 font-mono leading-5">
    <div class="flex items-center justify-between gap-3">
        <div>
            <p class="font-sans text-sm font-semibold text-white">{{ __('Provisioning output') }}</p>
            <p class="font-sans text-xs text-slate-400">
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
                <a href="{{ route('websites.provisioning-log.download', $website) }}" class="text-xs font-medium text-cyan-300 hover:underline">
                    {{ __('Download log') }}
                </a>
            @endif
            <span class="text-xs uppercase text-slate-400">{{ $website->provisioning_status }}</span>
        </div>
    </div>

    <div class="mt-4 flex max-h-96 flex-col overflow-y-auto">
        @forelse ($lines as $line)
            @if ($line === '') @continue @endif
            <div class="w-full">
                <span class="text-cyan-300">{{ $website->deployment_slug }}:~$</span>
                <span class="text-slate-100">{{ $line }}</span>
            </div>
        @empty
            <div class="text-slate-400">
                {{ $shouldPoll ? __('Waiting for website provisioning output…') : __('No website provisioning output to show.') }}
            </div>
        @endforelse
    </div>
</div>
