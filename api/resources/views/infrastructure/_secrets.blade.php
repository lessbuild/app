@php($secrets = session('secrets'))
@if (is_array($secrets) && $secrets !== [])
    <x-signal.ui.panel as="section" class="space-y-3 border-warning p-6" aria-labelledby="secrets-heading">
        <p class="ui-eyebrow">{{ __('Copy them now') }}</p>
        <h2 id="secrets-heading" class="text-lg font-extrabold text-ink">{{ __('Server passwords') }}</h2>
        <p class="text-sm text-muted">{{ __('This is the only time they’re shown. You sign in with the server’s SSH key; keep these for the console and MySQL.') }}</p>
        @foreach (['root' => __('Root password'), 'mysql' => __('MySQL root password')] as $key => $label)
            @if (isset($secrets[$key]))
                <div><p class="text-xs font-bold text-muted">{{ $label }}</p><x-signal.ui.code-block :code="$secrets[$key]" class="break-all whitespace-pre-wrap" /></div>
            @endif
        @endforeach
    </x-signal.ui.panel>
@endif
