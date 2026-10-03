@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Review #:id', ['id' => $review->id])" :description="__('Created by :name. It can be applied until :time, if nothing has changed since.', ['name' => $review->requester->name, 'time' => $review->expires_at->toDayDateTimeString()])">
    @foreach (['review', 'plan', 'bindings'] as $key)
        @error($key)<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror
    @endforeach
    @include('deploy._configuration-changes', ['plan' => $review->summary])
    @if ($review->application)
        <x-signal.ui.button :href="route('deploy.configuration.applications.show', [$project, $review->application->id])" variant="secondary">{{ __('See what applying it did') }}</x-signal.ui.button>
    @elseif ($review->expires_at->isPast())
        <x-signal.ui.alert tone="info">{{ __('This review has expired. Create a new one from the Configuration page.') }}</x-signal.ui.alert>
    @elseif (! $review->summary['apply_available'])
        <x-signal.ui.alert tone="warning">{{ __('Some objects need adopt: true before this can be applied.') }}</x-signal.ui.alert>
    @elseif ($canApply)
        <form method="POST" action="{{ route('deploy.configuration.reviews.apply', [$project, $review->id]) }}">@csrf<x-signal.ui.button type="submit" variant="primary">{{ __('Apply configuration') }}</x-signal.ui.button></form>
    @else
        <p class="text-sm text-muted">{{ __('Only :name can apply this review.', ['name' => $review->requester->name]) }}</p>
    @endif
</x-signal.layouts.project>
