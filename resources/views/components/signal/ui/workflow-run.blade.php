@props(['run'])

<x-signal.ui.card class="p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="ui-eyebrow">{{ __('Product activity') }}</p>
            <h3 class="mt-1 truncate text-base font-extrabold text-ink">{{ $run->title }}</h3>
            @if ($run->projectUrl && $run->projectName)
                <x-signal.ui.link :href="$run->projectUrl" size="inline" variant="muted" class="mt-1">
                    {{ __('Project: :name', ['name' => $run->projectName]) }}
                </x-signal.ui.link>
            @endif
        </div>
        <time class="shrink-0 text-xs text-subtle" datetime="{{ $run->recordedAt->toIso8601String() }}">
            {{ __('Started :time', ['time' => $run->recordedAt->diffForHumans()]) }}
        </time>
    </div>

    <ol class="mt-4 grid gap-3" aria-label="{{ __('Workflow steps') }}">
        @foreach ($run->steps as $step)
            <li class="rounded-control border border-line bg-surface-muted p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="ui-eyebrow">{{ $step->productLabel }}</p>
                        <h4 class="mt-1 text-sm font-extrabold text-ink">{{ $step->title }}</h4>
                    </div>
                    <x-signal.ui.badge :tone="$step->state->tone()">{{ $step->state->label() }}</x-signal.ui.badge>
                </div>
                <p class="mt-2 text-sm leading-6 text-muted">{{ $step->detail }}</p>
                @if ($step->recordedAt || $step->attemptedAt || $step->completedAt)
                    <p class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-subtle">
                        @if ($step->state->value === 'succeeded' && $step->recordedAt)
                            <span><time datetime="{{ $step->recordedAt->toIso8601String() }}">{{ __('Recorded :time', ['time' => $step->recordedAt->diffForHumans()]) }}</time></span>
                        @elseif ($step->state->value === 'pending' && ! $step->attemptedAt && $step->recordedAt)
                            <span><time datetime="{{ $step->recordedAt->toIso8601String() }}">{{ __('Queued :time', ['time' => $step->recordedAt->diffForHumans()]) }}</time></span>
                        @elseif ($step->recordedAt)
                            <span><time datetime="{{ $step->recordedAt->toIso8601String() }}">{{ __('Created :time', ['time' => $step->recordedAt->diffForHumans()]) }}</time></span>
                        @endif
                        @if ($step->attemptedAt)
                            <span><time datetime="{{ $step->attemptedAt->toIso8601String() }}">{{ __('Last attempted :time', ['time' => $step->attemptedAt->diffForHumans()]) }}</time></span>
                        @endif
                        @if ($step->completedAt)
                            <span><time datetime="{{ $step->completedAt->toIso8601String() }}">{{ __('Delivered :time', ['time' => $step->completedAt->diffForHumans()]) }}</time></span>
                        @endif
                    </p>
                @endif
                @if ($step->retryUrl)
                    <form method="POST" action="{{ $step->retryUrl }}" class="mt-3">
                        @csrf
                        <x-signal.ui.button type="submit" class="min-h-8 px-3 text-xs">{{ __('Retry this step') }}</x-signal.ui.button>
                    </form>
                @endif
                @if ($step->resultUrl)
                    <x-signal.ui.link :href="$step->resultUrl" size="sm" class="mt-3 inline-flex">
                        {{ __('Open in :product', ['product' => $step->productLabel]) }}
                    </x-signal.ui.link>
                @endif
            </li>
        @endforeach
    </ol>
</x-signal.ui.card>
