<x-signal.ui.table :caption="__('Queues')">
    <x-slot:head><tr><th scope="col">{{ __('Queue') }}</th><th scope="col">{{ __('Waiting') }}</th><th scope="col">{{ __('Running') }}</th><th scope="col">{{ __('Oldest') }}</th></tr></x-slot:head>
    @foreach ($queues as $queue)
        <tr>
            <td class="font-mono">{{ $queue->queue }} @unless ($queue->healthy)<x-signal.ui.badge tone="danger">{{ __('Backed up') }}</x-signal.ui.badge>@endunless</td>
            <td>{{ $queue->pending }}</td>
            <td>{{ $queue->reserved }}</td>
            <td>{{ $queue->pending === 0 ? '—' : trans_choice(':count minute|:count minutes', $queue->oldestMinutes) }}</td>
        </tr>
    @endforeach
</x-signal.ui.table>
