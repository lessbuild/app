{{-- The status widget customers embed with an iframe: one line, no scripts, refreshed by the browser each minute. --}}
@php($dot = ['operational' => '#129b78', 'maintenance' => '#2563eb', 'major_outage' => '#dc2626'][$overall] ?? '#d97706')
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="60">
    <meta name="robots" content="noindex">
    <title>{{ $page->name }}: {{ $overallLabel }}</title>
    <style>
        html, body { margin: 0; background: transparent; }
        a { display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; border: 1px solid #d7dde8; border-radius: 999px; background: #fff; color: #172a4b; font: 600 13px/1.4 system-ui, -apple-system, "Segoe UI", sans-serif; text-decoration: none; }
        a:hover, a:focus-visible { border-color: #172a4b; }
        span { width: 9px; height: 9px; border-radius: 50%; background: {{ $dot }}; flex: none; }
        @media (prefers-color-scheme: dark) { a { background: #0f172a; color: #e2e8f0; border-color: #334155; } a:hover, a:focus-visible { border-color: #e2e8f0; } }
    </style>
</head>
<body>
    <a href="{{ $page->publicUrl() }}" target="_blank" rel="noopener"><span aria-hidden="true"></span>{{ $overallLabel }}</a>
</body>
</html>
