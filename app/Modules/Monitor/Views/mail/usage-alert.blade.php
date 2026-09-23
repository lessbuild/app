<x-mail::message>
# Monthly usage alert

Your **{{ $workspace->name }}** workspace has reached **{{ $threshold }}%** of its monthly {{ config('app.name') }} event allowance.

<x-mail::panel>
**{{ number_format($summary['event_count']) }}** of **{{ number_format($summary['event_limit']) }}** events used ({{ $summary['percentage'] }}% shown)

{{ $summary['period_start']->format('Y-m-d') }} → {{ $summary['period_end']->format('Y-m-d') }} UTC
</x-mail::panel>

@if($threshold >= 100)
New deliveries remain encrypted and retryable while the workspace is at its allowance. Upgrade the plan before retrying them.
@else
This is an early warning. Review your traffic and upgrade before the monthly allowance is reached.
@endif

<x-mail::button :url="$billingUrl">
Review plans and usage
</x-mail::button>

This is an automated {{ config('app.name') }} notification. Usage is measured by event arrival time in UTC.
</x-mail::message>
