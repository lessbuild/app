<x-signal.ui.alert
    :tone="$passed ? 'success' : 'danger'"
    class="mt-8 border-l-4"
    role="status"
    aria-labelledby="system-health-summary"
>
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <p class="ui-eyebrow">{{ __('Current status') }}</p>
            <h2 id="system-health-summary" class="mt-1 text-2xl font-bold text-ink">
                {{ $passed ? __('Operational') : __('Needs attention') }}
            </h2>
            <p class="mt-1 text-sm text-muted">
                {{ trans_choice(':passed of :total check passed|:passed of :total checks passed', count($checks), ['passed' => $passedCount, 'total' => count($checks)]) }}
            </p>
        </div>
        <p class="text-sm text-muted">
            {{ __('Checked :time', ['time' => $checkedAt->toDayDateTimeString()]) }}
        </p>
    </div>
</x-signal.ui.alert>

<x-signal.ui.insights
    id="system-health-insights"
    class="mt-6"
    :summary="trans_choice(':passed of :total check passed|:passed of :total checks passed', count($checks), ['passed' => $passedCount, 'total' => count($checks)])"
>
    <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-signal.ui.stat
            :label="__('Checks')"
            :value="count($checks)"
            :description="__('Safe runtime checks in this snapshot.')"
        />
        <x-signal.ui.stat
            :label="__('Passed')"
            :value="$passedCount"
            :description="__('Checks reporting a healthy result.')"
        />
        <x-signal.ui.stat
            :label="__('Needs attention')"
            :value="count($checks) - $passedCount"
            :description="__('Checks requiring operator review.')"
        />
        <x-signal.ui.stat
            :label="__('Checked')"
            :value="$checkedAt->diffForHumans()"
            :description="$checkedAt->toDayDateTimeString()"
        />
    </dl>
</x-signal.ui.insights>

<section class="mt-6" aria-labelledby="system-health-checks">
    <div class="mb-4">
        <h2 id="system-health-checks" class="text-xl font-bold text-ink">{{ __('Diagnostic checks') }}</h2>
        <p class="mt-1 text-sm text-muted">
            {{ __('Values are deliberately summarized so credentials, queue payloads, and exception details never appear here.') }}
        </p>
    </div>

    <ul class="grid gap-4 lg:grid-cols-2" role="list">
        @foreach ($checks as $check)
            <li>
                <x-signal.ui.panel class="ui-panel h-full p-5">
                    <div class="flex items-start gap-3">
                        <x-signal.ui.badge :tone="$check['passed'] ? 'success' : 'danger'">
                            {{ $check['passed'] ? __('Pass') : __('Fail') }}
                        </x-signal.ui.badge>
                        <div class="min-w-0">
                            <h3 class="font-bold text-ink">{{ $check['name'] }}</h3>
                            <p class="mt-1 text-sm text-muted">{{ $check['detail'] }}</p>
                        </div>
                    </div>
                </x-signal.ui.panel>
            </li>
        @endforeach
    </ul>
</section>

<x-signal.ui.panel as="section" id="system-health-help" class="ui-panel mt-6 scroll-mt-24 border-l-4 p-5" aria-labelledby="system-health-help-title">
    <h2 id="system-health-help-title" class="font-bold text-ink">{{ __('When a check fails') }}</h2>
    <p class="mt-1 text-sm text-muted">
        {{ __('Use the failing check and its safe summary to guide investigation. Operators with shell access can run php artisan lessbuild:diagnose for the same current snapshot.') }}
    </p>
</x-signal.ui.panel>
