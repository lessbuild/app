<x-layouts.app>
    <x-layouts.partials.breadcrumbs :route="route('gallery.index')" :title="__('Back to gallery')" />

    <x-layouts.partials.heading
        :title="__('Community Feedback Inbox')"
        :description="__('Review anonymous reports across recipes you have published. Reporter identities are never shown.')"
    />

    @if (session('status'))
        <div class="my-4 rounded-sm border border-green-300 bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif

    @if ($filters['report'] || $filters['recipe'])
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-sm border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800">
            <span>{{ $filters['report']
                ? __('Showing the community report opened from your notification.')
                : __('Showing all matching feedback for the selected recipe.') }}</span>
            <a href="{{ route('gallery.reports.index', ['status' => 'all']) }}" class="font-semibold underline">{{ __('Show all feedback') }}</a>
        </div>
    @endif

    <form method="GET" action="{{ route('gallery.reports.index') }}" class="mt-6 rounded-lg border border-primary bg-primary p-4">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div>
                <label for="search" class="block text-xs font-semibold uppercase text-secondary">{{ __('Recipe') }}</label>
                <input id="search" name="search" type="search" maxlength="100" value="{{ $filters['search'] }}" placeholder="{{ __('Recipe name') }}" class="input secondary mt-1 w-full rounded-sm">
            </div>
            <div>
                <label for="status" class="block text-xs font-semibold uppercase text-secondary">{{ __('Review status') }}</label>
                <select id="status" name="status" class="input secondary mt-1 w-full rounded-sm">
                    <option value="unresolved" @selected($filters['status'] === 'unresolved')>{{ __('Needs review') }}</option>
                    <option value="resolved" @selected($filters['status'] === 'resolved')>{{ __('Resolved') }}</option>
                    <option value="all" @selected($filters['status'] === 'all')>{{ __('All reports') }}</option>
                </select>
            </div>
            <div>
                <label for="reason" class="block text-xs font-semibold uppercase text-secondary">{{ __('Issue type') }}</label>
                <select id="reason" name="reason" class="input secondary mt-1 w-full rounded-sm">
                    <option value="">{{ __('All issue types') }}</option>
                    @foreach ($reasons as $reason)
                        <option value="{{ $reason }}" @selected($filters['reason'] === $reason)>{{ str($reason)->headline() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="date_from" class="block text-xs font-semibold uppercase text-secondary">{{ __('Reported from') }}</label>
                <input id="date_from" name="date_from" type="date" value="{{ $filters['date_from'] }}" class="input secondary mt-1 w-full rounded-sm">
            </div>
            <div>
                <label for="date_to" class="block text-xs font-semibold uppercase text-secondary">{{ __('Reported to') }}</label>
                <input id="date_to" name="date_to" type="date" value="{{ $filters['date_to'] }}" class="input secondary mt-1 w-full rounded-sm">
            </div>
            <div>
                <label for="age" class="block text-xs font-semibold uppercase text-secondary">{{ __('Minimum age') }}</label>
                <select id="age" name="age" class="input secondary mt-1 w-full rounded-sm">
                    <option value="" @selected($filters['age'] === null)>{{ __('Any age') }}</option>
                    <option value="24h" @selected($filters['age'] === '24h')>{{ __('At least 24 hours') }}</option>
                    <option value="7d" @selected($filters['age'] === '7d')>{{ __('At least 7 days') }}</option>
                    <option value="30d" @selected($filters['age'] === '30d')>{{ __('At least 30 days') }}</option>
                </select>
            </div>
            <div>
                <label for="sort" class="block text-xs font-semibold uppercase text-secondary">{{ __('Sort') }}</label>
                <select id="sort" name="sort" class="input secondary mt-1 w-full rounded-sm">
                    <option value="newest" @selected($filters['sort'] === 'newest')>{{ __('Newest reports') }}</option>
                    <option value="oldest" @selected($filters['sort'] === 'oldest')>{{ __('Oldest reports') }}</option>
                    <option value="updated" @selected($filters['sort'] === 'updated')>{{ __('Recently updated') }}</option>
                    <option value="priority" @selected($filters['sort'] === 'priority')>{{ __('Issue priority') }}</option>
                </select>
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <button type="submit" class="button primary">{{ __('Apply filters') }}</button>
            <a href="{{ route('gallery.reports.export', array_filter($filters, fn ($value) => $value !== null)) }}" class="button primary">{{ __('Export CSV') }}</a>
            @if ($filters['search'] || $filters['status'] !== 'unresolved' || $filters['reason'] || $filters['date_from'] || $filters['date_to'] || $filters['age'] || $filters['sort'] !== 'newest' || $filters['recipe'] || $filters['report'])
                <a href="{{ route('gallery.reports.index') }}" class="button secondary">{{ __('Clear filters') }}</a>
            @endif
        </div>
    </form>

    <dl class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Matching reports') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['matching'] }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Needs review') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['unresolved'] }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Resolved') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['resolved'] }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Affected recipes') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['recipes'] }}</dd>
        </div>
    </dl>

    @if ($reports->isEmpty())
        <div class="mx-auto mt-6 max-w-3xl">
            <x-lists.empty
                :title="__('No community reports match these filters')"
                :description="__('Try changing or clearing the selected filters. New feedback on your published recipes will appear here.')"
            />
        </div>
    @else
        <div x-data="{
            openSelected: [],
            resolvedSelected: [],
            openIds: {{ Illuminate\Support\Js::from($reports->whereNull('resolved_at')->pluck('id')->values()) }},
            resolvedIds: {{ Illuminate\Support\Js::from($reports->whereNotNull('resolved_at')->pluck('id')->values()) }},
        }">
        @if ($reports->contains(fn ($report) => $report->resolved_at === null))
            <form id="bulk-resolve-form" method="POST" action="{{ route('gallery.reports.resolve-many') }}" class="mt-6 flex flex-wrap items-center gap-3" onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Mark the selected community reports as resolved?')) }})">
                @csrf
                @method('PATCH')
                <button type="submit" class="button secondary disabled:cursor-not-allowed disabled:opacity-50" x-bind:disabled="openSelected.length === 0">{{ __('Resolve Selected') }}</button>
                <button type="button" class="button tertiary" x-on:click="openSelected = openSelected.length === openIds.length ? [] : [...openIds]">
                    <span x-show="openSelected.length !== openIds.length">{{ __('Select All Open') }}</span>
                    <span x-show="openSelected.length === openIds.length" style="display: none">{{ __('Clear Open Selection') }}</span>
                </button>
                <span class="text-xs font-semibold text-secondary"><span x-text="openSelected.length">0</span> {{ __('selected') }}</span>
                <span class="text-xs text-secondary">{{ __('Select up to 20 reports on this page.') }}</span>
                <x-forms.errors name="reports" bag="bulkResolve" />
            </form>
        @endif
        @if ($reports->contains(fn ($report) => $report->resolved_at !== null))
            <form id="bulk-reopen-form" method="POST" action="{{ route('gallery.reports.reopen-many') }}" class="mt-3 flex flex-wrap items-center gap-3" onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Reopen the selected community reports? Resolution notes will be cleared.')) }})">
                @csrf
                @method('PATCH')
                <button type="submit" class="button secondary disabled:cursor-not-allowed disabled:opacity-50" x-bind:disabled="resolvedSelected.length === 0">{{ __('Reopen Selected') }}</button>
                <button type="button" class="button tertiary" x-on:click="resolvedSelected = resolvedSelected.length === resolvedIds.length ? [] : [...resolvedIds]">
                    <span x-show="resolvedSelected.length !== resolvedIds.length">{{ __('Select All Resolved') }}</span>
                    <span x-show="resolvedSelected.length === resolvedIds.length" style="display: none">{{ __('Clear Resolved Selection') }}</span>
                </button>
                <span class="text-xs font-semibold text-secondary"><span x-text="resolvedSelected.length">0</span> {{ __('selected') }}</span>
                <span class="text-xs text-secondary">{{ __('Select up to 20 resolved reports on this page.') }}</span>
                <x-forms.errors name="reports" bag="bulkReopen" />
            </form>
        @endif
        <div class="mt-6 space-y-4">
            @foreach ($reports as $report)
                <article id="report-{{ $report->id }}" class="scroll-mt-6 rounded-lg border border-primary bg-primary p-5">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                @if ($report->resolved_at === null)
                                    <input
                                        type="checkbox"
                                        name="reports[]"
                                        value="{{ $report->id }}"
                                        form="bulk-resolve-form"
                                        x-model.number="openSelected"
                                        aria-label="{{ __('Select report for :recipe', ['recipe' => $report->recipe->name]) }}"
                                        class="rounded-sm border-primary"
                                    >
                                @else
                                    <input
                                        type="checkbox"
                                        name="reports[]"
                                        value="{{ $report->id }}"
                                        form="bulk-reopen-form"
                                        x-model.number="resolvedSelected"
                                        aria-label="{{ __('Select resolved report for :recipe', ['recipe' => $report->recipe->name]) }}"
                                        class="rounded-sm border-primary"
                                    >
                                @endif
                                <span @class([
                                    'rounded-full px-2 py-1 text-xs font-semibold',
                                    'bg-red-100 text-red-700' => $report->reason === 'security',
                                    'bg-orange-100 text-orange-700' => $report->reason === 'broken',
                                    'bg-yellow-100 text-yellow-800' => $report->reason === 'misleading',
                                    'bg-purple-100 text-purple-700' => $report->reason === 'outdated',
                                    'bg-blue-100 text-blue-700' => $report->reason === 'other',
                                ])>{{ str($report->reason)->headline() }}</span>
                                <span @class([
                                    'rounded-full px-2 py-1 text-xs font-semibold',
                                    'bg-red-100 text-red-700' => $report->resolved_at === null,
                                    'bg-green-100 text-green-700' => $report->resolved_at !== null,
                                ])>{{ $report->resolved_at === null ? __('Needs review') : __('Resolved') }}</span>
                            </div>
                            <h2 class="mt-3 text-lg font-bold text-primary">
                                <a href="{{ $report->recipe->is_published ? route('gallery.show', $report->recipe) : route('recipes.edit', $report->recipe) }}" class="text-ternary">{{ $report->recipe->name }}</a>
                            </h2>
                            <p class="mt-1 text-xs text-secondary">
                                {{ str($report->recipe->category)->headline() }}
                                &middot;
                                {{ $report->recipe->is_published ? __('Published') : __('No longer published') }}
                            </p>
                        </div>
                        <div class="text-right text-xs text-secondary">
                            <span class="block">{{ __('Reported :date', ['date' => $report->created_at->diffForHumans()]) }}</span>
                            @if ($report->resolved_at)
                                <span class="mt-1 block">{{ __('Resolved :date', ['date' => $report->resolved_at->diffForHumans()]) }}</span>
                            @endif
                        </div>
                    </div>
                    <p class="mt-4 whitespace-pre-line text-sm text-secondary">{{ $report->details ?: __('No additional details were provided.') }}</p>
                    @if ($report->resolved_at && $report->resolution_note)
                        <div class="mt-3 rounded-sm border border-green-200 bg-green-50 p-3">
                            <p class="text-xs font-semibold uppercase text-green-700">{{ __('Resolution note') }}</p>
                            <p class="mt-1 whitespace-pre-line text-sm text-green-800">{{ $report->resolution_note }}</p>
                        </div>
                    @endif
                    <div class="mt-4">
                        @if ($report->resolved_at === null)
                            <form method="POST" action="{{ route('gallery.reports.resolve', [$report->recipe, $report]) }}" class="space-y-3">
                                @csrf
                                @method('PATCH')
                                <div>
                                    <label for="resolution_note_{{ $report->id }}" class="block text-xs font-semibold uppercase text-secondary">{{ __('Resolution note (optional)') }}</label>
                                    <textarea id="resolution_note_{{ $report->id }}" name="resolution_note" rows="2" maxlength="1000" class="input secondary mt-1 w-full rounded-sm" placeholder="{{ __('Briefly explain what was addressed.') }}"></textarea>
                                </div>
                                <button type="submit" class="button secondary">{{ __('Mark Resolved') }}</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('gallery.reports.resolution-note.update', [$report->recipe, $report]) }}" class="space-y-3">
                                @csrf
                                @method('PATCH')
                                <div>
                                    <label for="edit_resolution_note_{{ $report->id }}" class="block text-xs font-semibold uppercase text-secondary">{{ __('Resolution note') }}</label>
                                    <textarea id="edit_resolution_note_{{ $report->id }}" name="resolution_note" rows="2" maxlength="1000" class="input secondary mt-1 w-full rounded-sm" placeholder="{{ __('Briefly explain what was addressed.') }}">{{ $report->resolution_note }}</textarea>
                                    <p class="mt-1 text-xs text-secondary">{{ __('Leave empty to clear the note without reopening the report.') }}</p>
                                    <x-forms.errors name="resolution_note" />
                                </div>
                                <button type="submit" class="button secondary">{{ $report->resolution_note ? __('Update Resolution Note') : __('Add Resolution Note') }}</button>
                            </form>
                            <form method="POST" action="{{ route('gallery.reports.reopen', [$report->recipe, $report]) }}" class="mt-3">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="button secondary">{{ __('Reopen Report') }}</button>
                            </form>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $reports->links() }}
        </div>
        </div>
    @endif
</x-layouts.app>
