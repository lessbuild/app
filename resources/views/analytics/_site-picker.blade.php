{{-- Chooses the site for Analytics pages that show one site; keeps the other query parameters. --}}
@if (count($sites) > 1)
    <form method="GET" class="flex flex-wrap items-end gap-2">
        @foreach (request()->except('site') as $key => $value)
            @if (is_string($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
        @endforeach
        <x-signal.ui.select-field name="site" :label="__('Site')" :show-errors="false" onchange="this.form.requestSubmit()">
            @foreach ($sites as $option)
                <option value="{{ $option->id }}" @selected($site?->id === $option->id)>{{ $option->name }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <noscript><x-signal.ui.button type="submit" variant="secondary">{{ __('Show') }}</x-signal.ui.button></noscript>
    </form>
@endif
