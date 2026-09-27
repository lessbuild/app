@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__(':environment deliveries', ['environment' => $environment->name])" :description="__('Every batch of events this environment sent, and whether it was processed.')">
    <nav aria-label="{{ __('Delivery status') }}" class="flex flex-wrap gap-2">
        <x-signal.ui.button :href="route('monitoring.ingest.deliveries', [$project, $environment->id])" :variant="$status === null ? 'soft' : 'quiet'" size="sm">{{ __('All') }}</x-signal.ui.button>
        @foreach ($statuses as $option)
            <x-signal.ui.button :href="route('monitoring.ingest.deliveries', [$project, $environment->id, 'status' => $option->value])" :variant="$status === $option->value ? 'soft' : 'quiet'" size="sm">{{ $option->label() }}</x-signal.ui.button>
        @endforeach
    </nav>

    <x-signal.ui.table :caption="__('Deliveries')">
        <x-slot:head><tr><th scope="col">{{ __('Received (UTC)') }}</th><th scope="col">{{ __('Source') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Accepted') }}</th><th scope="col">{{ __('Duplicates') }}</th><th scope="col"><span class="sr-only">{{ __('Actions') }}</span></th></tr></x-slot:head>
        @forelse ($receipts as $receipt)
            <tr>
                <td class="whitespace-nowrap">{{ $receipt->received_at->format('Y-m-d H:i:s') }}</td>
                <td>{{ $receipt->source->value }}</td>
                <td>
                    <x-signal.ui.badge :tone="$receipt->status->tone()">{{ $receipt->status->label() }}</x-signal.ui.badge>
                    @if ($receipt->processingError())<p class="mt-1 text-xs text-muted">{{ __($receipt->processingError()) }}</p>@endif
                </td>
                <td>{{ number_format($receipt->accepted_count) }}</td>
                <td>{{ number_format($receipt->duplicate_count) }}</td>
                <td class="text-right">
                    @if ($canRetry && $receipt->status === \App\Enums\IngestStatus::Failed && $receipt->getAttribute('payload_available'))
                        <form method="POST" action="{{ route('monitoring.ingest.retry', [$project, $receipt->id]) }}">
                            @csrf
                            <x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Retry') }}</x-signal.ui.button>
                        </form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="py-10 text-center text-muted">{{ __('No deliveries yet.') }}</td></tr>
        @endforelse
    </x-signal.ui.table>
    @include('telemetry._pager', ['paginator' => $receipts])
</x-signal.layouts.project>
