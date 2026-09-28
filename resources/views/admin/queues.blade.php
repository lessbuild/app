<x-signal.layouts.admin :title="__('Queues')" :description="__('What waits in each queue, and the jobs that failed. Retrying runs a job again; deleting drops it.')">
    @include('admin._queues')

    <x-signal.ui.settings-section :title="__('Failed jobs')" :description="__('The latest 100.')">
        @if ($jobs === [])
            <p class="p-4 text-sm text-muted sm:p-6">{{ __('No failed jobs.') }}</p>
        @else
            <div class="flex flex-wrap gap-2 border-b border-line p-4 sm:px-6">
                <form method="POST" action="{{ route('admin.queues.retry', 'all') }}">@csrf<x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Retry all') }}</x-signal.ui.button></form>
                <form method="POST" action="{{ route('admin.queues.forget', 'all') }}">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Delete all') }}</x-signal.ui.button></form>
            </div>
            <ul class="divide-y divide-line">
                @foreach ($jobs as $job)
                    <li class="flex flex-wrap items-start justify-between gap-3 px-4 py-3 text-sm sm:px-6">
                        <div class="min-w-0">
                            <p class="font-bold">{{ $job['job'] ?: __('Unknown job') }} <span class="font-mono text-xs text-muted">{{ $job['queue'] }} · {{ $job['failed_at'] }}</span></p>
                            <p class="break-all text-xs text-muted">{{ $job['error'] }}</p>
                        </div>
                        <div class="flex gap-2">
                            <form method="POST" action="{{ route('admin.queues.retry', $job['uuid']) }}">@csrf<x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Retry') }}</x-signal.ui.button></form>
                            <form method="POST" action="{{ route('admin.queues.forget', $job['uuid']) }}">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Delete') }}</x-signal.ui.button></form>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-signal.ui.settings-section>
</x-signal.layouts.admin>
