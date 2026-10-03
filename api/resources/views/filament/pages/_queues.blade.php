{{-- The queues: what's waiting and running, and whether one is backed up. --}}
<x-filament::section :heading="__('Queues')">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="text-gray-500 dark:text-gray-400"><tr><th class="py-2 pe-4 font-medium">{{ __('Queue') }}</th><th class="py-2 pe-4 font-medium">{{ __('Waiting') }}</th><th class="py-2 pe-4 font-medium">{{ __('Running') }}</th><th class="py-2 font-medium">{{ __('Oldest') }}</th></tr></thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($queues as $queue)
                    <tr>
                        <td class="py-2 pe-4 font-mono">{{ $queue->queue }} @unless ($queue->healthy)<x-filament::badge color="danger" class="ms-2 inline-flex">{{ __('Backed up') }}</x-filament::badge>@endunless</td>
                        <td class="py-2 pe-4">{{ $queue->pending }}</td>
                        <td class="py-2 pe-4">{{ $queue->reserved }}</td>
                        <td class="py-2">{{ $queue->pending === 0 ? '—' : trans_choice(':count minute|:count minutes', $queue->oldestMinutes) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-filament::section>
