{{-- The Health page: every check, the queues and the retention rules. --}}
@php($failing = collect($checks)->where('passed', false)->count())
<x-filament-panels::page>
    <x-filament::section :heading="$failing === 0 ? __('Every check passes.') : trans_choice(':count check fails.|:count checks fail.', $failing)" :icon="$failing === 0 ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle'" :icon-color="$failing === 0 ? 'success' : 'danger'">
        <ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">
            @foreach ($checks as $check)
                <li class="flex flex-wrap items-start justify-between gap-3 py-2">
                    <span class="font-medium">{{ __($check->name) }}</span>
                    <span class="flex min-w-0 items-center gap-3">
                        <span class="text-gray-500 dark:text-gray-400">{{ $check->detail }}</span>
                        <x-filament::badge :color="$check->passed ? 'success' : 'danger'">{{ $check->passed ? __('Pass') : __('Fail') }}</x-filament::badge>
                    </span>
                </li>
            @endforeach
        </ul>
    </x-filament::section>

    @include('filament.pages._queues')

    <x-filament::section :heading="__('Retention')" :description="__('How long each kind of data is kept, and the job that deletes it.')">
        <ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">
            @foreach ($retention as $row)
                <li class="flex flex-wrap items-center justify-between gap-3 py-2">
                    <span>{{ $row['data'] }}</span>
                    <span class="text-gray-500 dark:text-gray-400">{{ $row['keeps'] }} · <span class="font-mono text-xs">{{ $row['job'] }}</span></span>
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-panels::page>
