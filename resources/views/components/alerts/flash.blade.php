@foreach (['success', 'info', 'error'] as $level)
    @if (session()->has($level))
        <div @class([
            'mb-4 rounded-sm border px-4 py-3 text-sm',
            'border-green-300 bg-green-50 text-green-800' => $level === 'success',
            'border-blue-300 bg-blue-50 text-blue-800' => $level === 'info',
            'border-red-300 bg-red-50 text-red-800' => $level === 'error',
        ]) role="status">
            {{ session($level) }}
        </div>
    @endif
@endforeach
