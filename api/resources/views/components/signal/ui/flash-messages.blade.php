@foreach (['success' => 'success', 'info' => 'info', 'error' => 'danger'] as $level => $tone)
    @if (session()->has($level))
        <x-signal.ui.alert :tone="$tone" class="mb-4" role="status" data-ui-feedback="flash">
            {{ session($level) }}
        </x-signal.ui.alert>
    @endif
@endforeach
