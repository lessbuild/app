<x-signal.layouts.account :account="$account" :title="__('Your reports')" :description="__('Recipes you reported, and what their publishers said.')">
    @include('recipes._nav')

    @if ($reports->isEmpty())
        <x-signal.ui.empty-state icon="check" :title="__('No reports')" :description="__('Report a recipe from its gallery page.')" />
    @else
        <x-signal.ui.table :caption="__('Your reports')">
            <x-slot:head><tr><th scope="col">{{ __('Recipe') }}</th><th scope="col">{{ __('Reason') }}</th><th scope="col">{{ __('Status') }}</th></tr></x-slot:head>
            @foreach ($reports as $report)
                <tr>
                    <td>@if ($report->recipe->is_published)<a href="{{ route('recipes.gallery.show', $report->recipe->id) }}" class="font-bold text-primary hover:underline">{{ $report->recipe->name }}</a>@else{{ $report->recipe->name }} <span class="text-xs text-muted">{{ __('(no longer published)') }}</span>@endif</td>
                    <td>{{ $report->reason->label() }}</td>
                    <td>
                        <x-signal.ui.badge :tone="$report->status === 'resolved' ? 'success' : 'warning'">{{ $report->status === 'resolved' ? __('Resolved') : __('Open') }}</x-signal.ui.badge>
                        @if ($report->resolution_note)<span class="block text-xs text-muted">{{ $report->resolution_note }}</span>@endif
                    </td>
                </tr>
            @endforeach
        </x-signal.ui.table>
    @endif
</x-signal.layouts.account>
