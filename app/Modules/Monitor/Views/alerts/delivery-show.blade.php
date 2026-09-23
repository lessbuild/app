@extends('monitor::layouts.app')
@section('title', 'Alert delivery')
@section('breadcrumb', 'Alert destinations')
@section('content')
<a href="{{ route('monitor.alert-destinations.show', $delivery->destination) }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">← Destination history</a>
<x-monitor::ui.page-header :title="$delivery->status->label()">
    <x-slot:metadata><code class="break-all text-xs text-muted">{{ $delivery->id }}</code></x-slot:metadata>
</x-monitor::ui.page-header>
<section class="ui-panel grid gap-4 p-6 sm:grid-cols-2">
    <div><p class="text-xs text-muted dark:text-subtle">Event / destination</p><p class="mt-1 font-semibold">{{ ucfirst($delivery->event) }} · {{ $delivery->destination->name }}</p></div>
    <div><p class="text-xs text-muted dark:text-subtle">Attempts / latest HTTP status</p><p class="mt-1">{{ $delivery->attempt_count }} / {{ $delivery->http_status ?? 'No HTTP response' }}</p></div>
    <div><p class="text-xs text-muted dark:text-subtle">Next attempt or processing lease (UTC)</p><p class="mt-1">{{ $delivery->next_attempt_at?->format('Y-m-d H:i:s') ?? 'None' }}</p></div>
    <div><p class="text-xs text-muted dark:text-subtle">Diagnostic code</p><p class="mt-1 break-words font-mono text-xs">{{ $delivery->last_error_code ?? 'None' }}</p></div>
    <p class="text-xs leading-5 text-muted sm:col-span-2 dark:text-subtle">Accepted confirms provider acknowledgement only. Uncertain means the provider may already have received the message. Credentials, request payloads and raw provider errors are deliberately excluded from this history.</p>
    @if($delivery->incident_id)<a href="{{ route('monitor.incidents.show', $delivery->incident_id) }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">View incident →</a>@endif
</section>
@if($delivery->status->retryable())
<form method="POST" action="{{ route('monitor.alert-deliveries.retry', $delivery) }}" class="ui-panel shadow-none space-y-4 border-warning p-5 dark:border-warning">
    @csrf<input type="hidden" name="generation" value="{{ $delivery->generation }}">
    <p class="text-sm">Retry uses the current destination configuration and the original event. Check the receiver first: an uncertain attempt may already have succeeded. The delivery ID stays the same.</p>
    <x-monitor::ui.choice id="retry-confirm" name="confirm" label="I reviewed the previous attempt and understand that retrying may create a duplicate." required />
    <x-monitor::ui.button>Queue retry</x-monitor::ui.button>
</form>
@endif
<section class="space-y-4"><h2 class="text-lg font-bold">Attempts</h2>
<div class="ui-card overflow-x-auto"><x-monitor::ui.table caption="Notification delivery attempts" :framed="false">
    <x-slot:head><tr><th scope="col">Attempt</th><th scope="col">Result</th><th scope="col">Diagnostic / HTTP</th><th scope="col">Started (UTC)</th></tr></x-slot:head>
@forelse($attempts as $attempt)<tr><td>{{ $attempt->number }}</td><td>{{ $attempt->status->label() }}</td><td>{{ $attempt->error_code ?? 'None' }} / {{ $attempt->http_status ?? '—' }}</td><td class="whitespace-nowrap">{{ $attempt->started_at->format('Y-m-d H:i:s') }}</td></tr>
@empty<tr><td colspan="4" class="py-10 text-center text-muted dark:text-subtle">No transport attempts yet.</td></tr>@endforelse
</x-monitor::ui.table></div>{{ $attempts->links() }}</section>
@endsection
