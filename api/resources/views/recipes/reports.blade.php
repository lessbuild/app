<x-signal.layouts.account :account="$account" :title="__('Reports on our recipes')" :description="__('What people reported about the recipes this account published. Resolving one tells the reporter, with your note.')">
    @include('recipes._nav')
    @if (session('status'))
        <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif

    @forelse ($reports as $report)
        <x-signal.ui.settings-section :title="$report->recipe->name.' · '.$report->reason->label()" :description="__('From :name, :when', ['name' => $report->user->name, 'when' => $report->updated_at?->diffForHumans()])">
            <div class="grid gap-3 p-4 text-sm sm:p-6">
                @if ($report->details)<p class="whitespace-pre-line">{{ $report->details }}</p>@endif
                @if ($report->status === 'resolved')
                    <p class="text-muted"><x-signal.ui.badge tone="success">{{ __('Resolved') }}</x-signal.ui.badge> {{ __('by :name', ['name' => $report->resolver->name ?? __('someone')]) }}@if ($report->resolution_note) · {{ $report->resolution_note }}@endif</p>
                    <form method="POST" action="{{ route('account.recipes.reports.update', $report->id) }}">@csrf @method('PUT')<input type="hidden" name="resolved" value="0"><x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Reopen') }}</x-signal.ui.button></form>
                @else
                    <form method="POST" action="{{ route('account.recipes.reports.update', $report->id) }}" class="grid gap-3">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="resolved" value="1">
                        <x-signal.ui.textarea-field :id="'note-'.$report->id" name="resolution_note" :label="__('Note for the reporter (optional)')" rows="2" maxlength="2000" :restore="false" />
                        <div><x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Resolve') }}</x-signal.ui.button></div>
                    </form>
                @endif
            </div>
        </x-signal.ui.settings-section>
    @empty
        <x-signal.ui.empty-state icon="check" :title="__('No reports')" :description="__('Nobody has reported this account’s recipes.')" />
    @endforelse
</x-signal.layouts.account>
