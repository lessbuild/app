<x-layouts.app>
    <x-layouts.partials.breadcrumbs
        :title="__('Back to Builds')"
        :route="route('builds.index')"
    />

    <x-layouts.partials.heading
        :title="__('Build #:id', ['id' => $build->id])"
        :description="$build->repository->name"
    >
        <x-slot:buttons>
            @if ($build->status === \App\Models\Build::STATUS_RUNNING && $build->remote_process_id && $build->remote_process_path)
                <form method="POST" action="{{ route('builds.cancel', $build) }}">
                    @csrf
                    <button
                        type="submit"
                        class="button primary"
                        onclick="return confirm({{ Illuminate\Support\Js::from(__('Stop this deployment on the remote server?')) }})"
                    >
                        {{ __('Cancel deployment') }}
                    </button>
                </form>
            @endif
            <a href="{{ route('repositories.show', $build->repository) }}" class="button primary">
                {{ __('View repository') }}
            </a>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    <dl class="mt-6 grid gap-4 rounded-lg border border-primary bg-primary p-5 sm:grid-cols-3">
        <div>
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Status') }}</dt>
            <dd class="mt-1 font-medium text-primary">{{ ucfirst($build->status) }}</dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Started') }}</dt>
            <dd class="mt-1 text-primary">{{ $build->started_at?->format('Y-m-d H:i:s T') ?? __('Not started') }}</dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Finished') }}</dt>
            <dd class="mt-1 text-primary">{{ $build->finished_at?->format('Y-m-d H:i:s T') ?? __('Not finished') }}</dd>
        </div>
    </dl>

    @if ($build->failure_message)
        <div class="mt-6 rounded border border-red-300 bg-red-50 p-4 text-red-800">
            <strong>{{ __('Deployment failed:') }}</strong> {{ $build->failure_message }}
        </div>
    @endif

    @if ($build->status === \App\Models\Build::STATUS_CANCELED)
        <div class="mt-6 rounded border border-amber-300 bg-amber-50 p-4 text-amber-800">
            {{ __('This deployment was canceled before it completed.') }}
        </div>
    @endif

    <section class="mt-8">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-2xl font-bold text-primary">{{ __('Deployment log') }}</h2>
            @if ($deploymentLog)
                <span class="text-xs text-secondary">{{ __('Captured :time', ['time' => $deploymentLog->updated_at->diffForHumans()]) }}</span>
            @endif
        </div>

        @if ($deploymentLog)
            <pre class="max-h-[36rem] overflow-auto whitespace-pre-wrap break-words rounded-lg bg-slate-950 p-5 font-mono text-xs leading-5 text-slate-100">{{ $deploymentLog->log }}</pre>
        @else
            <x-lists.empty
                :title="__('No deployment log yet')"
                :description="__('Output will appear here when the remote deployment finishes or fails.')"
            />
        @endif
    </section>
</x-layouts.app>
