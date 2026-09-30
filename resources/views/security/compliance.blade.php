@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Compliance')" :description="__('Evidence for SOC 2, ISO 27001 and security questionnaires, from what BuildPusher already records.')">
    @unless ($included)
        <x-signal.ui.alert tone="info">{{ __('Compliance reports come with the Team Security plan.') }} <a class="ui-link" href="{{ route('account.billing', ['tab' => 'security']) }}">{{ __('See plans') }}</a></x-signal.ui.alert>
    @endunless

    <div class="grid gap-4 sm:grid-cols-3">
        <x-signal.ui.card class="p-5"><p class="text-xs font-bold text-muted">{{ __('Last access review') }}</p><p class="mt-2 text-xl font-extrabold text-ink">{{ $lastReview?->created_at?->toFormattedDateString() ?? __('Never') }}</p></x-signal.ui.card>
        <x-signal.ui.card class="p-5"><p class="text-xs font-bold text-muted">{{ __('Open critical and high findings') }}</p><p class="mt-2 text-xl font-extrabold text-ink">{{ number_format($openSerious) }}</p></x-signal.ui.card>
        <x-signal.ui.card class="p-5"><p class="text-xs font-bold text-muted">{{ __('Findings resolved this year') }}</p><p class="mt-2 text-xl font-extrabold text-ink">{{ number_format($resolved) }}</p></x-signal.ui.card>
    </div>

    <x-signal.ui.settings-section :title="__('Evidence pack')" :description="__('A ZIP of spreadsheets (CSV) with a README that maps each to the SOC 2 and ISO 27001 controls it supports: members and two-factor, API tokens, access reviews, every deploy and who approved it, vulnerabilities and how each was handled, scans, patching, blocked attacks, backups and restore tests, incidents, and the audit log.')">
        <form method="POST" action="{{ route('security.compliance.download', $project) }}" class="flex flex-wrap items-end gap-3 p-4 sm:p-6">
            @csrf
            <x-signal.ui.select-field name="months" :label="__('Covering')" :show-errors="false">
                <option value="3">{{ __('The last 3 months') }}</option>
                <option value="6">{{ __('The last 6 months') }}</option>
                <option value="12" selected>{{ __('The last 12 months') }}</option>
            </x-signal.ui.select-field>
            <x-signal.ui.button type="submit" variant="primary" :disabled="! $canManage || ! $included">{{ __('Download evidence') }}</x-signal.ui.button>
        </form>
    </x-signal.ui.settings-section>
</x-signal.layouts.project>
