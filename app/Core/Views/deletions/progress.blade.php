<x-signal.layouts.account :title="__('Deletion progress')" :description="__('Your request is saved. You can return to this page while cleanup continues.')">
    <x-signal.ui.panel as="section" class="space-y-4 p-6" aria-live="polite">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-extrabold text-ink">{{ $deletion->kind === 'account' ? __('Account deletion') : __('Workspace deletion') }}</h2>
            <x-signal.ui.badge :tone="$deletion->status === 'completed' ? 'success' : ($deletion->status === 'blocked' ? 'warning' : 'neutral')">{{ $deletion->status === 'completed' ? __('Complete') : ($deletion->status === 'blocked' ? __('Needs attention') : __('In progress')) }}</x-signal.ui.badge>
        </div>
        <p class="text-sm leading-6 text-muted">{{ $deletion->status === 'completed' ? __('All connected apps have acknowledged cleanup and the shared records have been removed or anonymized.') : ($deletion->phase === 'prepare' ? __('Stopping new activity and waiting for running operations to finish in every app.') : __('Removing app data and exports. Completed steps are preserved if another app needs a retry.')) }}</p>
        @if ($deletion->last_error_code)<x-signal.ui.alert tone="warning">{{ \App\Core\Services\Deletion\DeletionMessages::reason($deletion->last_error_code) }}</x-signal.ui.alert>@endif
        <ul class="space-y-3">
            @foreach ($steps as $step)
                <li class="rounded-control border border-line p-4">
                    <div class="flex flex-wrap justify-between gap-3 text-sm"><span class="font-bold text-ink">{{ ucfirst($step->product) }} · {{ $step->kind === 'account' ? __('Account') : __('Workspace') }}</span><span class="text-muted">{{ match ($step->status) { 'ready' => __('Ready for cleanup'), 'completed' => __('Complete'), 'processing' => __('Working'), 'blocked', 'failed' => __('Needs attention'), default => __('Waiting') } }}</span></div>
                    @if ($step->last_error_code)<p class="mt-2 text-sm leading-6 text-muted">{{ \App\Core\Services\Deletion\DeletionMessages::reason($step->last_error_code) }}</p>@endif
                </li>
            @endforeach
        </ul>
        <div class="flex flex-wrap gap-3">
            <x-signal.ui.button :href="route('platform.deletions.progress', $receiptKey)" variant="secondary">{{ __('Refresh progress') }}</x-signal.ui.button>
            @if ($deletion->status === 'blocked')
                <form method="POST" action="{{ route('platform.deletions.retry', $receiptKey) }}">@csrf<x-signal.ui.button type="submit" variant="primary">{{ __('Retry unfinished cleanup') }}</x-signal.ui.button></form>
            @endif
        </div>
    </x-signal.ui.panel>
    @if (is_string($receiptToken))<x-signal.blocks.deletion-receipt :receipt-key="$receiptKey" :receipt-token="$receiptToken" />@endif
    <x-signal.ui.panel as="section" class="space-y-3 p-6">
        <h2 class="text-lg font-extrabold text-ink">{{ __('Retained records') }}</h2>
        <ul class="list-inside list-disc space-y-2 text-sm leading-6 text-muted">@foreach ($deletion->retained ?? [] as $retained)<li>{{ $retained }}</li>@endforeach</ul>
    </x-signal.ui.panel>
</x-signal.layouts.account>
