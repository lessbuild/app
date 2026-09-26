<x-mail::message>
# Daily issue digest

Here is the latest issue activity for **{{ $digest['workspace_name'] }}**.

<x-mail::panel>
**{{ number_format($digest['open_count']) }} open** · **{{ number_format($digest['critical_open_count']) }} critical** · **{{ number_format($digest['snoozed_count']) }} snoozed**

{{ $digest['from'] }} → {{ $digest['until'] }}
</x-mail::panel>

@if($digest['new_issues'] !== [])
## New issues

@foreach($digest['new_issues'] as $issue)
- **[{{ $issue['title'] }}]({{ $issue['url'] }})** · {{ $issue['application'] }} · {{ ucfirst($issue['severity']) }} · {{ number_format($issue['occurrences']) }} occurrence(s)
  @if($issue['location']) {{ $issue['location'] }} · @endif{{ $issue['seen_at'] }}
@endforeach
@endif

@if($digest['resolved_issues'] !== [])
## Resolved issues

@foreach($digest['resolved_issues'] as $issue)
- **[{{ $issue['title'] }}]({{ $issue['url'] }})** · {{ $issue['application'] }} · {{ ucfirst($issue['severity']) }} · {{ $issue['seen_at'] }}
@endforeach
@endif

<x-mail::button :url="$digest['issues_url']">
Review issues
</x-mail::button>

This is an automated {{ config('app.name') }} notification. Issue details are redacted before they are sent by email.
</x-mail::message>
