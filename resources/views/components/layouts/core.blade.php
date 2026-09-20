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

                const renderModalLoadError = (dialog, trigger, error, fallbackUrl) => {
                    const content = dialog.querySelector('[data-modal-content]');
                    if (! content) {
                        return;
                    }

                    const wrapper = document.createElement('div');
                    wrapper.className = 'space-y-4 p-5 sm:p-6';
                    wrapper.setAttribute('role', 'alert');

                    const title = document.createElement('p');
                    title.className = 'font-semibold text-primary';
                    title.textContent = error.code === 'session'
                        ? 'Your session has expired.'
                        : 'This dialog could not be loaded.';
                    wrapper.append(title);

                    const message = document.createElement('p');
                    message.className = 'text-sm text-secondary';
                    message.textContent = error.code === 'session'
                        ? 'Sign in again, then reopen this action.'
                        : 'Check your connection and try again, or open the full page to continue.';
                    wrapper.append(message);

                    const actions = document.createElement('div');
                    actions.className = 'flex flex-wrap gap-2';

                    const retry = document.createElement('button');
                    retry.type = 'button';
                    retry.className = 'button button--primary';
                    retry.textContent = 'Retry';
                    retry.addEventListener('click', () => void loadModalContent(dialog, trigger));
                    actions.append(retry);

                    const pageUrl = error.code === 'session' && error.loginUrl
                        ? error.loginUrl
                        : fallbackUrl;
                    if (pageUrl) {
                        const fullPage = document.createElement('a');
                        fullPage.className = 'button button--secondary';
                        fullPage.href = pageUrl;
                        fullPage.textContent = error.code === 'session' ? 'Sign in' : 'Open full page';
                        actions.append(fullPage);
                    }

                    wrapper.append(actions);
                    content.replaceChildren(wrapper);
                };

                const loadModalContent = async (dialog, trigger) => {
                    const contentUrl = trigger.dataset.modalContentUrl;
                    const content = dialog.querySelector('[data-modal-content]');

                    if (! contentUrl || ! content) {
                        return;
                    }

                    const url = new URL(contentUrl, window.location.href);

                    if (url.origin !== window.location.origin) {
                        return;
                    }

                    if (dialog.dataset.modalContentLoaded === 'true'
                        && dialog.dataset.modalContentUrl === url.href) {
                        return;
                    }

                    dialog.modalRequest?.abort();
                    const requestId = (dialog.modalRequestId ?? 0) + 1;
                    dialog.modalRequestId = requestId;
                    const controller = new AbortController();
                    dialog.modalRequest = controller;
                    dialog.dataset.modalContentLoading = 'true';
                    content.setAttribute('aria-busy', 'true');
                    content.innerHTML = '<p class="p-5 text-sm text-secondary">Loading…</p>';

                    try {
                        const response = await fetch(url, {
                            signal: controller.signal,
                            headers: {
                                Accept: 'text/html',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                        });

                        const finalUrl = new URL(response.url || url.href, window.location.href);
                        if (response.redirected && /\/(?:login|sign-in)(?:\/|$)/.test(finalUrl.pathname)) {
                            const error = new Error('Modal session expired.');
                            error.code = 'session';
                            error.loginUrl = finalUrl.href;
                            throw error;
                        }

                        if (response.status === 401 || response.status === 419) {
                            const error = new Error('Modal session expired.');
                            error.code = 'session';
                            throw error;
                        }

                        if (! response.ok) {
                            const error = new Error(`Modal content request failed with ${response.status}`);
                            error.status = response.status;
                            throw error;
                        }

                        if (dialog.modalRequestId !== requestId) {
                            return;
                        }

                        content.innerHTML = await response.text();
                        window.Alpine?.initTree?.(content);
                        document.dispatchEvent(new CustomEvent('modal:content-loaded', {
                            detail: { content, dialog },
                        }));
                        initialiseModals();
                        dialog.dataset.modalContentLoaded = 'true';
                        dialog.dataset.modalContentUrl = url.href;
                    } catch (error) {
                        if (error.name === 'AbortError' || dialog.modalRequestId !== requestId) {
                            return;
                        }

                        renderModalLoadError(dialog, trigger, error, trigger instanceof HTMLAnchorElement ? trigger.href : null);
                    } finally {
                        if (dialog.modalRequestId !== requestId) {
                            return;
                        }

                        content.removeAttribute('aria-busy');
                        delete dialog.dataset.modalContentLoading;
                        if (dialog.modalRequest === controller) {
                            delete dialog.modalRequest;
                        }
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

                            dialog.modalRequest?.abort();
                            delete dialog.modalRequest;
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
                            if (typeof dialog.showModal !== 'function'
                                || event.button !== 0
                                || event.metaKey
                                || event.ctrlKey
                                || event.shiftKey
                                || event.altKey
                                || trigger instanceof HTMLAnchorElement && trigger.target === '_blank') {
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
                            const currentUrl = new URL(window.location.href);
                            const triggerUrl = trigger instanceof HTMLAnchorElement
                                ? new URL(trigger.href, window.location.href)
                                : currentUrl;
                            const isInitialTrigger = triggerUrl.pathname === currentUrl.pathname
                                && triggerUrl.search === currentUrl.search
                                && triggerUrl.hash === currentUrl.hash;

                            if (! isInitialTrigger) {
                                return;
                            }

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
