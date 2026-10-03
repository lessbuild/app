{{-- The Email page: problems that would stop mail arriving, the settings, and the last test. --}}
<x-filament-panels::page>
    @if ($problems === [])
        <x-filament::section icon="heroicon-o-check-circle" icon-color="success" :heading="__('Email is set up to be delivered. Send a test to be sure.')" />
    @else
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger" :heading="__('Email won’t arrive yet')">
            <ul class="list-disc space-y-1 ps-5 text-sm">
                @foreach ($problems as $problem)<li>{{ $problem }}</li>@endforeach
            </ul>
        </x-filament::section>
    @endif

    <x-filament::section :heading="__('Settings')">
        <ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">
            @foreach ($settings as [$label, $value])
                <li class="flex flex-wrap items-center justify-between gap-3 py-2"><span>{{ __($label) }}</span><span class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ $value }}</span></li>
            @endforeach
        </ul>
    </x-filament::section>

    @if ($lastTest)
        <x-filament::section :heading="__('Last test')">
            <p class="text-sm">
                {{ __(':when to :to with :mailer —', ['when' => \Illuminate\Support\Carbon::parse($lastTest['at'])->diffForHumans(), 'to' => $lastTest['to'], 'mailer' => $lastTest['mailer']]) }}
                <x-filament::badge :color="$lastTest['sent'] ? 'success' : 'danger'" class="inline-flex">{{ $lastTest['sent'] ? __('sent') : __('failed: :error', ['error' => $lastTest['error']]) }}</x-filament::badge>
            </p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
