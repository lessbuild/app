@php($failing = collect($checks)->where('passed', false)->count())
<x-signal.layouts.admin :title="__('Health')" :description="__('Checked just now. Nothing here shows secrets, so the report is safe to share.')">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <x-signal.ui.alert :tone="$failing === 0 ? 'success' : 'danger'" role="status">{{ $failing === 0 ? __('Every check passes.') : trans_choice(':count check fails.|:count checks fail.', $failing) }}</x-signal.ui.alert>
        <x-signal.ui.button :href="route('admin.health.report')" variant="secondary" size="sm">{{ __('Download report') }}</x-signal.ui.button>
    </div>
    <x-signal.ui.table :caption="__('Checks')">
        <x-slot:head><tr><th scope="col">{{ __('Check') }}</th><th scope="col">{{ __('Result') }}</th><th scope="col">{{ __('Detail') }}</th></tr></x-slot:head>
        @foreach ($checks as $check)
            <tr>
                <td>{{ __($check->name) }}</td>
                <td><x-signal.ui.badge :tone="$check->passed ? 'success' : 'danger'">{{ $check->passed ? __('Pass') : __('Fail') }}</x-signal.ui.badge></td>
                <td class="text-muted">{{ $check->detail }}</td>
            </tr>
        @endforeach
    </x-signal.ui.table>
    @include('admin._queues')
</x-signal.layouts.admin>
