@props(['page', 'parameters' => []])

@php
    // A person's saved filters for this page, as chips, and a modal to save the filters showing now.
    $user = auth()->user();
    $parameters = \App\Support\SavedViewPages::parameters($page, $parameters);
    $current = \App\Support\SavedViewPages::query($page, request()->query());
    ksort($current);
    $views = $user === null ? collect() : \App\Models\SavedView::query()->where('user_id', $user->id)->where('page', $page)
        ->where('account_id', $user->current_account_id)->orderBy('name')->get()
        ->filter(fn (\App\Models\SavedView $view): bool => $view->parameters === $parameters);
    $modalId = 'save-view-'.\Illuminate\Support\Str::slug($page);
@endphp

@if ($user !== null)
    <div {{ $attributes->class(['flex flex-wrap items-center gap-2']) }}>
        <span class="text-xs font-bold uppercase tracking-[0.12em] text-subtle">{{ __('Saved views') }}</span>
        @forelse ($views as $view)
            @php($query = $view->query)
            @php(ksort($query))
            <span @class(['ui-chip inline-flex items-center gap-1', 'ring-2 ring-primary/40' => $query === $current])>
                <a href="{{ \App\Support\SavedViewPages::url($view->page, $view->parameters, $view->query) }}" class="font-semibold hover:underline" @if ($query === $current) aria-current="true" @endif>{{ $view->name }}</a>
                <form method="POST" action="{{ route('saved-views.destroy', $view->id) }}" class="inline">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="grid size-5 place-items-center rounded-full text-muted hover:bg-surface-muted hover:text-danger" aria-label="{{ __('Delete saved view :name', ['name' => $view->name]) }}"><x-signal.ui.icon name="x" class="size-3" /></button>
                </form>
            </span>
        @empty
            <span class="text-xs text-muted">{{ __('None yet.') }}</span>
        @endforelse
        <x-signal.ui.button variant="quiet" size="sm" data-modal-trigger="{{ $modalId }}" :disabled="$current === []" :title="$current === [] ? __('Filter the list first') : null">{{ __('Save view') }}</x-signal.ui.button>
    </div>

    <x-signal.overlays.modal :id="$modalId" :title="__('Save this view')" :description="__('Keep these filters so you can come back to them in one click. Only you can see your saved views.')" :open="$errors->has('saved_view_name') && old('saved_view_page') === $page">
        <form method="POST" action="{{ route('saved-views.store') }}" class="grid gap-4">
            @csrf
            <input type="hidden" name="saved_view_page" value="{{ $page }}">
            @foreach ($parameters as $key => $value)<input type="hidden" name="parameters[{{ $key }}]" value="{{ $value }}">@endforeach
            @foreach ($current as $key => $value)<input type="hidden" name="query[{{ $key }}]" value="{{ $value }}">@endforeach
            <ul class="flex flex-wrap gap-2" aria-label="{{ __('Filters being saved') }}">
                @foreach ($current as $key => $value)<li class="ui-chip">{{ $key }}: {{ \Illuminate\Support\Str::limit($value, 40) }}</li>@endforeach
            </ul>
            <x-signal.ui.input-field :id="$modalId.'-name'" name="saved_view_name" :label="__('Name')" maxlength="60" required :placeholder="__('e.g. Production errors this week')" />
            <div class="flex justify-end"><x-signal.ui.button type="submit" variant="primary">{{ __('Save view') }}</x-signal.ui.button></div>
        </form>
    </x-signal.overlays.modal>
@endif
