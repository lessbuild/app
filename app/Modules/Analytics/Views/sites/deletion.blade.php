@extends('analytics::layouts.app')

@section('content')
<div class="mx-auto max-w-3xl space-y-6">
    <x-signal.ui.page-header
        :eyebrow="__('Website deletion')"
        :title="__('Deletion status')"
        :description="__('Collection is fenced while Analytics removes the site data and private export files.')"
    >
        <x-slot:actions><x-signal.ui.button :href="route('analytics.dashboard')">{{ __('Back to overview') }}</x-signal.ui.button></x-slot:actions>
    </x-signal.ui.page-header>

    <x-signal.ui.card class="p-6">
        @if ($outcome->completed())
            <x-signal.ui.alert tone="success" role="status">{{ __('The Analytics site has been deleted.') }}</x-signal.ui.alert>
        @else
            <x-signal.ui.alert tone="warning" role="status">
                {{ $outcome->status === 'blocked' ? __('Deletion needs operator attention before cleanup can finish.') : __('The site is fenced. Cleanup is waiting and will remain retryable.') }}
            </x-signal.ui.alert>
            <p class="mt-4 text-sm text-muted">{{ __('Request reference: :id', ['id' => $outcome->requestId]) }}</p>
            <form class="mt-5 flex justify-end" method="POST" action="{{ route('analytics.sites.deletion-retry', $outcome->requestId) }}">
                @csrf
                <x-signal.ui.button type="submit" variant="primary">{{ __('Retry cleanup') }}</x-signal.ui.button>
            </form>
        @endif
    </x-signal.ui.card>
</div>
@endsection
