<x-layouts.app>
    <x-layouts.partials.breadcrumbs :route="route('gallery.reports.mine')" :title="__('Back to my reports')" />

    <x-layouts.partials.heading
        :title="__('My Report Status')"
        :description="__('Review the current state of your private community report and any response from the contributor.')"
    />

    @if ($unreadUpdate)
        <div class="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-blue-300 bg-blue-50 p-4 text-blue-900" role="status">
            <div>
                <p class="font-semibold">{{ __('New contributor update') }}</p>
                <p class="mt-1 text-sm">{{ __('Review the current state below, then mark this update as reviewed.') }}</p>
            </div>
            <form method="POST" action="{{ route('notifications.read', $unreadUpdate) }}">
                @csrf
                <button type="submit" class="button primary">{{ __('Mark update reviewed') }}</button>
            </form>
        </div>
    @endif

    <section class="mt-6 rounded-lg border border-primary bg-primary p-5" aria-labelledby="report-status-heading">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase text-secondary">{{ __('Recipe') }}</p>
                <h2 id="report-status-heading" class="mt-1 text-xl font-bold text-primary">{{ $report->recipe->name }}</h2>
                <p class="mt-1 text-sm text-secondary">{{ str($report->recipe->category)->headline() }}</p>
            </div>
            <span @class([
                'rounded-full px-3 py-1 text-xs font-semibold',
                'bg-red-100 text-red-700' => $report->resolved_at === null,
                'bg-green-100 text-green-700' => $report->resolved_at !== null,
            ])>{{ $report->resolved_at === null ? __('Needs contributor review') : __('Resolved by contributor') }}</span>
        </div>

        @if ($report->recipe->is_published && $report->recipe->published_at)
            <a href="{{ route('gallery.show', $report->recipe) }}#gallery-report-heading" class="mt-4 inline-block font-medium text-ternary underline">
                {{ __('View or update this report in the gallery') }}
            </a>
        @else
            <div class="mt-4 rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                {{ __('This recipe is no longer published. Your report status remains available, but the gallery recipe cannot be opened or updated.') }}
            </div>
        @endif

        <dl class="mt-5 grid gap-4 sm:grid-cols-3">
            <div>
                <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Issue type') }}</dt>
                <dd class="mt-1 text-sm font-medium text-primary">{{ str($report->reason)->headline() }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Reported') }}</dt>
                <dd class="mt-1 text-sm text-primary">{{ $report->created_at->toDayDateTimeString() }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Last updated') }}</dt>
                <dd class="mt-1 text-sm text-primary">{{ $report->updated_at->toDayDateTimeString() }}</dd>
            </div>
        </dl>

        <div class="mt-5">
            <h3 class="text-xs font-semibold uppercase text-secondary">{{ __('Your report details') }}</h3>
            <p class="mt-2 whitespace-pre-line text-sm text-primary">{{ $report->details ?: __('No additional details were provided.') }}</p>
        </div>

        @if ($report->resolved_at && $report->resolution_note)
            <div class="mt-5 rounded border border-green-200 bg-green-50 p-4 text-green-800">
                <h3 class="text-sm font-semibold">{{ __('Contributor resolution note') }}</h3>
                <p class="mt-2 whitespace-pre-line text-sm">{{ $report->resolution_note }}</p>
            </div>
        @endif

        <form method="POST" action="{{ route('gallery.report.destroy', $report->recipe) }}" class="mt-6" onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Withdraw your report for :recipe? This cannot be undone.', ['recipe' => $report->recipe->name])) }})">
            @csrf
            @method('DELETE')
            <button type="submit" class="button secondary">{{ __('Withdraw Report') }}</button>
        </form>
    </section>
</x-layouts.app>
