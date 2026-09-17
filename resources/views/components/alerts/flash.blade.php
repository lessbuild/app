@foreach (['success', 'info', 'error'] as $level)
    @if (session()->has($level))
        <div @class([
            'ui-alert mb-4',
            'ui-alert--success' => $level === 'success',
            'ui-alert--info' => $level === 'info',
            'ui-alert--danger' => $level === 'error',
        ]) role="status">
            {{ session($level) }}
        </div>
    @endif
@endforeach
