@props([
    'title' => null,
    'description' => null,
    'canonical' => null,
    'indexable' => false,
    'livewire' => true,
])

@php($pageTitle = $title ? $title.' · '.config('app.name') : config('app.name'))

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="motion-safe:scroll-smooth" style="--vh:8px;">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#111827">
        <meta name="robots" content="{{ $indexable ? 'index, follow' : 'noindex, nofollow' }}">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="manifest" href="/manifest.webmanifest">
        <title>{{ $pageTitle }}</title>
        @if ($description)
            <meta name="description" content="{{ $description }}">
        @endif
        @if ($indexable && $canonical)
            <link rel="canonical" href="{{ $canonical }}">
            <meta property="og:type" content="website">
            <meta property="og:site_name" content="{{ config('app.name') }}">
            <meta property="og:title" content="{{ $pageTitle }}">
            <meta property="og:url" content="{{ $canonical }}">
            @if ($description)
                <meta property="og:description" content="{{ $description }}">
            @endif
            <meta name="twitter:card" content="summary">
            <meta name="twitter:title" content="{{ $pageTitle }}">
            @if ($description)
                <meta name="twitter:description" content="{{ $description }}">
            @endif
        @endif
        @vite('resources/css/app.css')
        @if (! $livewire)
            @vite('resources/js/alpine.js')
        @endif
        @if ($livewire)
            @livewireStyles
        @endif
    </head>
    <body class="font-sans text-sm text-primary bg-primary">
        {{ $slot }}

        <script>
            (() => {
                const media = window.matchMedia('(min-width: 64rem)');

                const syncResponsiveDetails = () => {
                    document.querySelectorAll('[data-responsive-details]').forEach((details) => {
                        if (media.matches) {
                            details.open = true;
                            details.dataset.responsiveDetailsAutoOpened = 'true';

                            return;
                        }

                        if (details.dataset.responsiveDetailsInitialized !== 'true') {
                            const mobileExpanded = details.dataset.responsiveDetailsMobileExpanded
                                ?? details.dataset.responsiveDetailsMobileOpen;

                            details.open = mobileExpanded === 'true';
                            details.dataset.responsiveDetailsInitialized = 'true';
                            details.dataset.responsiveDetailsAutoOpened = 'false';
                        }
                    });
                };

                document.querySelectorAll('[data-responsive-details]').forEach((details) => {
                    details.addEventListener('toggle', () => {
                        if (! media.matches) {
                            details.dataset.responsiveDetailsAutoOpened = 'false';
                        }
                    });
                });

                syncResponsiveDetails();
                media.addEventListener('change', syncResponsiveDetails);
            })();
        </script>

        @if ($livewire)
            @livewireScripts
        @endif

        <script>
            (() => {
                const cleanModalUrl = () => {
                    const url = new URL(window.location.href);

                    if (! url.searchParams.has('dialog')) {
                        return;
                    }

                    url.searchParams.delete('dialog');
                    window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
                };

                const initialiseModals = () => {
                    document.querySelectorAll('[data-modal-trigger]').forEach((trigger) => {
                        if (trigger.dataset.modalTriggerBound === 'true') {
                            return;
                        }

                        const dialog = document.getElementById(trigger.dataset.modalTrigger);

                        if (! dialog) {
                            return;
                        }

                        trigger.dataset.modalTriggerBound = 'true';

                        dialog.addEventListener('close', () => {
                            trigger.setAttribute('aria-expanded', 'false');

                            if (dialog.dataset.modalHistory === 'pushed'
                                && window.history.state?.modal === dialog.id) {
                                dialog.dataset.modalHistory = 'backing';
                                window.history.back();
                            } else {
                                cleanModalUrl();
                                delete dialog.dataset.modalHistory;
                            }

                            window.requestAnimationFrame(() => trigger.focus());
                        });

                        trigger.addEventListener('click', (event) => {
                            if (typeof dialog.showModal !== 'function') {
                                return;
                            }

                            event.preventDefault();

                            if (! dialog.open) {
                                dialog.showModal();
                            }

                            trigger.setAttribute('aria-expanded', 'true');
                            dialog.dataset.modalHistory = 'pushed';

                            if (trigger instanceof HTMLAnchorElement) {
                                const url = new URL(trigger.href, window.location.href);

                                window.history.pushState(
                                    { ...(window.history.state ?? {}), modal: dialog.id },
                                    '',
                                    `${url.pathname}${url.search}${url.hash}`,
                                );
                            }
                        });

                        if (dialog.dataset.modalInitialOpen === 'true') {
                            trigger.setAttribute('aria-expanded', 'true');

                            if (typeof dialog.showModal === 'function' && dialog.open) {
                                dialog.removeAttribute('open');
                                dialog.showModal();
                                dialog.dataset.modalHistory = 'server';
                            }
                        }
                    });
                };

                window.addEventListener('popstate', () => {
                    document.querySelectorAll('dialog[data-modal-history][open]').forEach((dialog) => {
                        if (! ['pushed', 'server'].includes(dialog.dataset.modalHistory)) {
                            return;
                        }

                        dialog.dataset.modalHistory = 'popped';
                        dialog.close('history');
                    });
                });

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', initialiseModals, { once: true });
                } else {
                    initialiseModals();
                }

                document.addEventListener('livewire:navigated', initialiseModals);
            })();
        </script>

        @stack('scripts')
        <script src="/service-worker-register.js" defer></script>
    </body>
</html>
