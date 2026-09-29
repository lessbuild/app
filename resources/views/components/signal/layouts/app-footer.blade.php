@props(['shell'])

{{-- The signed-in app's footer, fixed to the bottom of the window: whether the platform is working, and quick ways to
     search, get help, see what's new and send feedback without leaving the page. --}}
@php($operational = $shell->platformOperational)
<footer class="app-footer fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface/95 backdrop-blur" aria-label="{{ __('Footer') }}">
    <div class="ui-layout-gutter mx-auto flex h-10 w-full max-w-content items-center justify-between gap-4 text-xs text-muted">
        <a href="{{ route('platform.status') }}" class="inline-flex min-w-0 items-center gap-2 font-semibold hover:text-ink">
            <span @class(['size-2 shrink-0 rounded-full', 'bg-success' => $operational === true, 'bg-warning' => $operational === false, 'bg-line' => $operational === null]) aria-hidden="true"></span>
            <span class="truncate">{{ match ($operational) { true => __('All systems working'), false => __('Some systems have problems'), null => __('System status') } }}</span>
        </a>
        <nav class="flex shrink-0 items-center gap-1 sm:gap-2" aria-label="{{ __('Footer links') }}">
            <button type="button" class="hidden items-center gap-1.5 rounded-control px-2 py-1 font-semibold hover:bg-surface-muted hover:text-ink sm:inline-flex" aria-controls="signal-command-palette" aria-haspopup="dialog" data-signal-command-open>
                {{ __('Search') }} <kbd class="ui-kbd">⌘K</kbd>
            </button>
            <a href="{{ route('help') }}" class="rounded-control px-2 py-1 font-semibold hover:bg-surface-muted hover:text-ink">{{ __('Help') }}</a>
            <a href="{{ route('changelog') }}" class="hidden rounded-control px-2 py-1 font-semibold hover:bg-surface-muted hover:text-ink sm:inline" data-modal-trigger="whats-new" data-modal-history-url="{{ request()->fullUrlWithQuery(['dialog' => 'whats-new']) }}">{{ __('What’s new') }}</a>
            <button type="button" class="rounded-control px-2 py-1 font-semibold hover:bg-surface-muted hover:text-ink" data-modal-trigger="feedback-modal">{{ __('Feedback') }}</button>
            <span class="hidden pl-2 text-subtle md:inline">© {{ now()->year }} {{ config('app.name') }}</span>
        </nav>
    </div>
</footer>
