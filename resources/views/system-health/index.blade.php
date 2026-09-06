<x-layouts.app>
    <x-layouts.partials.heading
        icon="chip"
        :title="__('System Health')"
        :description="__('Read-only checks for the application runtime, storage, queue, and production automation.')"
    >
        <x-slot:buttons>
            <a href="{{ route('system-health.report') }}" class="button primary">
                {{ __('Download report') }}
            </a>
            <a href="{{ route('system-health.index') }}" class="button primary">
                {{ __('Run checks again') }}
            </a>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    <section
        @class([
            'mt-8 rounded-lg border p-5',
            'border-green-500 bg-green-50' => $passed,
            'border-red-500 bg-red-50' => ! $passed,
        ])
        role="status"
        aria-labelledby="system-health-summary"
    >
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase text-secondary">{{ __('Current status') }}</p>
                <h2 id="system-health-summary" class="mt-1 text-2xl font-bold text-primary">
                    {{ $passed ? __('Operational') : __('Needs attention') }}
                </h2>
                <p class="mt-1 text-sm text-secondary">
                    {{ trans_choice(':passed of :total check passed|:passed of :total checks passed', count($checks), ['passed' => $passedCount, 'total' => count($checks)]) }}
                </p>
            </div>
            <p class="text-sm text-secondary">
                {{ __('Checked :time', ['time' => $checkedAt->toDayDateTimeString()]) }}
            </p>
        </div>
    </section>

    <section class="mt-6" aria-labelledby="system-health-checks">
        <div class="mb-4">
            <h2 id="system-health-checks" class="text-xl font-bold text-primary">{{ __('Diagnostic checks') }}</h2>
            <p class="mt-1 text-sm text-secondary">
                {{ __('Values are deliberately summarized so credentials, queue payloads, and exception details never appear here.') }}
            </p>
        </div>

        <ul class="grid gap-4 lg:grid-cols-2" role="list">
            @foreach ($checks as $check)
                <li class="rounded-lg border border-primary bg-primary p-5">
                    <div class="flex items-start gap-3">
                        <span @class([
                            'inline-flex rounded-full px-2.5 py-1 text-xs font-bold uppercase',
                            'bg-green-100 text-green-800' => $check['passed'],
                            'bg-red-100 text-red-800' => ! $check['passed'],
                        ])>
                            {{ $check['passed'] ? __('Pass') : __('Fail') }}
                        </span>
                        <div class="min-w-0">
                            <h3 class="font-bold text-primary">{{ $check['name'] }}</h3>
                            <p class="mt-1 text-sm text-secondary">{{ $check['detail'] }}</p>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>

    <aside class="mt-6 rounded-lg border border-primary bg-secondary p-5" aria-labelledby="system-health-help">
        <h2 id="system-health-help" class="font-bold text-primary">{{ __('When a check fails') }}</h2>
        <p class="mt-1 text-sm text-secondary">
            {{ __('Use the failing check and its safe summary to guide investigation. Operators with shell access can run php artisan lessbuild:diagnose for the same current snapshot.') }}
        </p>
    </aside>
</x-layouts.app>
