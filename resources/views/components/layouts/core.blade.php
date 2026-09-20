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

        <script>
            (() => {
                const mobileMedia = window.matchMedia('(max-width: 63.999rem)');
                const editableSelector = 'input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([type="button"]):not([type="submit"]), select, textarea, [contenteditable="true"]';

                const syncMobileKeyboard = () => {
                    const viewport = window.visualViewport;
                    const active = document.activeElement;
                    const editing = active instanceof Element && active.matches(editableSelector);
                    const keyboardOpen = Boolean(
                        mobileMedia.matches
                        && viewport
                        && editing
                        && window.innerHeight - viewport.height > 120,
                    );

                    document.querySelectorAll('[data-mobile-shell]').forEach((shell) => {
                        shell.toggleAttribute('data-mobile-keyboard-open', keyboardOpen);
                    });
                };

                document.addEventListener('focusin', syncMobileKeyboard);
                document.addEventListener('focusout', () => window.setTimeout(syncMobileKeyboard, 0));
                window.addEventListener('resize', syncMobileKeyboard);
                mobileMedia.addEventListener('change', syncMobileKeyboard);
                window.visualViewport?.addEventListener('resize', syncMobileKeyboard);
                syncMobileKeyboard();
            })();
        </script>

        <script>
            (() => {
                const closeMobileFilter = (panel) => {
                    if (! panel || ! mobileMedia.matches) {
                        return false;
                    }

                    panel.removeAttribute('open');
                    panel.querySelector('[data-mobile-filter-summary]')?.focus();

                    return true;
                };
                const mobileMedia = window.matchMedia('(max-width: 63.999rem)');

                document.addEventListener('click', (event) => {
                    const backdrop = event.target.closest('[data-mobile-filter-backdrop]');

                    if (! backdrop) {
                        return;
                    }

                    closeMobileFilter(backdrop.previousElementSibling);
                });

                document.addEventListener('keydown', (event) => {
                    if (event.key !== 'Escape') {
                        return;
                    }

                    const panel = document.querySelector('[data-mobile-filter-panel][open]');

                    if (closeMobileFilter(panel)) {
                        event.stopPropagation();
                    }
                });
            })();
        </script>

        <script>
            (() => {
                let onlineMessageTimer;

                const updateNetworkStatus = (online, temporary = false) => {
                    document.querySelectorAll('[data-network-status]').forEach((status) => {
                        window.clearTimeout(onlineMessageTimer);
                        status.textContent = online
                            ? status.dataset.onlineMessage
                            : status.dataset.offlineMessage;
                        status.toggleAttribute('hidden', online && temporary === false);
                    });

                    document.querySelectorAll('[data-mobile-shell]').forEach((shell) => {
                        shell.toggleAttribute('data-mobile-offline', ! online);
                    });

                    if (online && temporary) {
                        onlineMessageTimer = window.setTimeout(() => {
                            document.querySelectorAll('[data-network-status]').forEach((status) => {
                                status.setAttribute('hidden', '');
                            });
                        }, 4000);
                    }
                };

                window.addEventListener('offline', () => updateNetworkStatus(false));
                window.addEventListener('online', () => updateNetworkStatus(true, true));

                if (navigator.onLine === false) {
                    updateNetworkStatus(false);
                }
            })();
        </script>

        @if ($livewire)
            @livewireScripts
        @endif

        <script>
            (() => {
                const syncModalScrollLock = () => {
                    const modalOpen = Boolean(document.querySelector('dialog[data-modal-sheet][open]'));

                    document.documentElement.toggleAttribute('data-modal-open', modalOpen);
                    document.body?.toggleAttribute('data-modal-open', modalOpen);
                };

                const loadModalContent = async (dialog, trigger) => {
                    const contentUrl = trigger.dataset.modalContentUrl;
                    const content = dialog.querySelector('[data-modal-content]');

                    if (! contentUrl || ! content || dialog.dataset.modalContentLoaded === 'true') {
                        return;
                    }

                    const url = new URL(contentUrl, window.location.href);

                    if (url.origin !== window.location.origin) {
                        return;
                    }

                    dialog.dataset.modalContentLoading = 'true';
                    content.setAttribute('aria-busy', 'true');

                    try {
                        const response = await fetch(url, {
                            headers: {
                                Accept: 'text/html',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                        });

                        if (! response.ok) {
                            throw new Error(`Modal content request failed with ${response.status}`);
                        }

                        content.innerHTML = await response.text();
                        dialog.dataset.modalContentLoaded = 'true';
                    } catch (error) {
                        content.innerHTML = '<p class="text-sm text-secondary">Unable to load this content. Open the recipe details page instead.</p>';
                    } finally {
                        content.removeAttribute('aria-busy');
                        delete dialog.dataset.modalContentLoading;
                    }
                };

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
                            syncModalScrollLock();

                            if (dialog.modalTrigger !== trigger) {
                                return;
                            }

                            dialog.modalTrigger = null;
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

                            syncModalScrollLock();

                            dialog.modalTrigger = trigger;
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

                            void loadModalContent(dialog, trigger);
                        });

                        if (dialog.dataset.modalInitialOpen === 'true') {
                            trigger.setAttribute('aria-expanded', 'true');
                            dialog.modalTrigger ??= trigger;

                            if (typeof dialog.showModal === 'function' && dialog.open) {
                                dialog.removeAttribute('open');
                                dialog.showModal();
                                dialog.dataset.modalHistory = 'server';
                            }

                            syncModalScrollLock();

                            void loadModalContent(dialog, trigger);
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

                syncModalScrollLock();

                document.addEventListener('livewire:navigated', initialiseModals);
            })();
        </script>

        @stack('scripts')
        <script src="/service-worker-register.js" defer></script>
    </body>
</html>
