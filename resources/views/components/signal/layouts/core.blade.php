@props([
    'title' => null,
    'description' => null,
    'canonical' => null,
    'indexable' => false,
    'livewire' => true,
    'productKey' => null,
])

@php($pageTitle = $title ? $title.' · '.config('app.name') : config('app.name'))
@php($userPreferences = auth()->user()?->preferences ?? [])
@php($defaultAppearance = in_array($userPreferences['theme'] ?? null, ['light', 'dark'], true) ? $userPreferences['theme'] : 'system')

<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    class="min-h-full"
    style="--vh:8px;"
    data-storage-namespace="buildpusher"
    data-default-preset="modern"
    data-default-appearance="{{ $defaultAppearance }}"
    data-default-palette="graphite"
    data-default-density="comfortable"
    data-default-corners="subtle"
    data-default-font="system"
    data-default-motion="system"
    data-default-contrast="default"
    data-product="{{ $productKey ?? '' }}"
>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#f4f7fb" data-theme-color>
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
        <script>
            (() => {
                const root = document.documentElement;
                const namespace = root.dataset.storageNamespace || 'signal-starter';
                const allowed = {
                    preset: ['modern', 'editorial', 'compact', 'noir', 'ocean', 'forest', 'sunset'],
                    appearance: ['system', 'light', 'dark'],
                    palette: ['violet', 'blue', 'emerald', 'rose', 'neutral', 'indigo', 'teal', 'amber', 'graphite'],
                    density: ['compact', 'comfortable'],
                    corners: ['subtle', 'soft', 'rounded'],
                    font: ['system', 'editorial'],
                    motion: ['system', 'reduced'],
                    contrast: ['default', 'high'],
                };
                const defaults = {
                    preset: 'modern',
                    appearance: 'system',
                    palette: 'violet',
                    density: 'comfortable',
                    corners: 'rounded',
                    font: 'system',
                    motion: 'system',
                    contrast: 'default',
                };
                const fallback = (name) => root.dataset[`default${name[0].toUpperCase()}${name.slice(1)}`] || defaults[name];
                const read = (name) => {
                    try {
                        const shared = new URLSearchParams(window.location.search).get(`theme_${name}`);

                        if (allowed[name]?.includes(shared)) {
                            return shared;
                        }

                        const stored = localStorage.getItem(`${namespace}-${name}`);

                        return allowed[name]?.includes(stored) ? stored : fallback(name);
                    } catch (_) {
                        return fallback(name);
                    }
                };
                const appearance = read('appearance');
                const dark = appearance === 'dark'
                    || (appearance === 'system' && window.matchMedia?.('(prefers-color-scheme: dark)').matches);

                root.classList.toggle('dark', dark);
                root.classList.toggle('light', ! dark);
                root.dataset.appearance = appearance;
                root.dataset.preset = read('preset');
                root.dataset.palette = read('palette');
                root.dataset.density = read('density');
                root.dataset.corners = read('corners');
                root.dataset.font = read('font');
                root.dataset.motion = read('motion');
                root.dataset.contrast = read('contrast');
            })();
        </script>
        @vite('resources/css/app.css')
        @vite('resources/js/signal-theme-init.js')
        @vite('resources/js/signal-theme.js')
        @vite('resources/js/signal-drawer.js')
        @if($productKey)
            @vite('resources/js/app.js')
        @endif
        @if (! $livewire)
            @vite('resources/js/alpine.js')
        @endif
        @if ($livewire)
            @livewireStyles
        @endif
    </head>
    <body class="antialiased transition-colors">
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
                const mobileMedia = window.matchMedia('(max-width: 63.999rem)');
                const filterDialogs = () => document.querySelectorAll('[data-filter-dialog]');
                const dispatchModalStateChange = () => document.dispatchEvent(new CustomEvent('modal:state-changed'));

                // Closed filter dialogs remain visible as a no-JavaScript fallback
                // until this marker is set. The component's noscript-free markup is
                // still a usable inline form while the enhancement is unavailable.
                document.documentElement.setAttribute('data-modal-js-ready', '');

                const focusFilterTrigger = (dialog) => {
                    document.querySelector(`[data-filter-dialog-trigger][aria-controls="${CSS.escape(dialog.id)}"]`)?.focus();
                };

                const openMobileFilter = (dialog, trigger) => {
                    if (! mobileMedia.matches || typeof dialog.showModal !== 'function') {
                        return;
                    }

                    if (dialog.open) {
                        dialog.close();
                    }

                    dialog.showModal();
                    trigger?.setAttribute('aria-expanded', 'true');
                    dispatchModalStateChange();
                };

                const syncFilterDialogs = () => {
                    filterDialogs().forEach((dialog) => {
                        const trigger = document.querySelector(`[data-filter-dialog-trigger][aria-controls="${CSS.escape(dialog.id)}"]`);

                        if (mobileMedia.matches) {
                            if (dialog.dataset.filterInitialOpen === 'true' && ! dialog.matches(':modal')) {
                                openMobileFilter(dialog, trigger);
                            }

                            return;
                        }

                        if (dialog.matches(':modal')) {
                            dialog.close();
                        }

                        trigger?.setAttribute('aria-expanded', 'false');
                    });

                    dispatchModalStateChange();
                };

                document.addEventListener('click', (event) => {
                    const trigger = event.target.closest('[data-filter-dialog-trigger]');

                    if (! trigger || ! mobileMedia.matches) {
                        return;
                    }

                    const dialog = document.getElementById(trigger.getAttribute('aria-controls'));

                    if (! dialog) {
                        return;
                    }

                    event.preventDefault();
                    openMobileFilter(dialog, trigger);
                });

                filterDialogs().forEach((dialog) => {
                    dialog.addEventListener('close', () => {
                        const trigger = document.querySelector(`[data-filter-dialog-trigger][aria-controls="${CSS.escape(dialog.id)}"]`);
                        trigger?.setAttribute('aria-expanded', 'false');
                        dispatchModalStateChange();

                        if (mobileMedia.matches) {
                            window.requestAnimationFrame(() => focusFilterTrigger(dialog));
                        }
                    });
                });

                mobileMedia.addEventListener('change', syncFilterDialogs);
                syncFilterDialogs();
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

            <script>
                (() => {
                    const selector = 'dialog[data-livewire-dialog]';

                    const closeFromDialog = (dialog) => {
                        dialog.querySelector('[data-livewire-dialog-close]')?.click();
                    };

                    const bindLivewireDialog = (dialog) => {
                        if (dialog.dataset.livewireDialogBound === 'true') {
                            return;
                        }

                        dialog.dataset.livewireDialogBound = 'true';
                        dialog.addEventListener('cancel', (event) => {
                            event.preventDefault();
                            closeFromDialog(dialog);
                        });
                        dialog.addEventListener('click', (event) => {
                            if (event.target === dialog) {
                                closeFromDialog(dialog);
                            }
                        });
                    };

                    const syncLivewireDialogs = () => {
                        document.querySelectorAll(selector).forEach((dialog) => {
                            bindLivewireDialog(dialog);

                            if (! dialog.hasAttribute('open')
                                || dialog.matches(':modal')
                                || typeof dialog.showModal !== 'function') {
                                return;
                            }

                            dialog.removeAttribute('open');

                            try {
                                dialog.showModal();
                            } catch (_) {
                                dialog.setAttribute('open', '');
                            }
                        });

                        document.dispatchEvent(new CustomEvent('modal:state-changed'));
                    };

                    const observer = new MutationObserver(syncLivewireDialogs);
                    observer.observe(document.body, {
                        attributes: true,
                        attributeFilter: ['open'],
                        childList: true,
                        subtree: true,
                    });

                    document.addEventListener('livewire:navigated', syncLivewireDialogs);
                    document.addEventListener('livewire:initialized', syncLivewireDialogs);
                    syncLivewireDialogs();
                })();
            </script>
        @endif

        <script>
            (() => {
                const syncModalScrollLock = () => {
                    const mobileFilterOpen = window.matchMedia('(max-width: 63.999rem)').matches
                        && Boolean(document.querySelector('dialog[data-filter-dialog][open]'));
                    const modalOpen = Boolean(document.querySelector('dialog[data-modal-sheet][open]:not([data-filter-dialog])'))
                        || mobileFilterOpen;

                    document.documentElement.toggleAttribute('data-modal-open', modalOpen);
                    document.body?.toggleAttribute('data-modal-open', modalOpen);
                };

                document.addEventListener('modal:state-changed', syncModalScrollLock);

                const renderModalLoadError = (dialog, trigger, error, fallbackUrl) => {
                    const content = dialog.querySelector('[data-modal-content]');
                    if (! content) {
                        return;
                    }

                    const wrapper = document.createElement('div');
                    wrapper.className = 'space-y-4 p-5 sm:p-6';
                    wrapper.setAttribute('role', 'alert');

                    const title = document.createElement('p');
                    title.className = 'font-semibold text-ink';
                    title.textContent = error.code === 'session'
                        ? 'Your session has expired.'
                        : 'This dialog could not be loaded.';
                    wrapper.append(title);

                    const message = document.createElement('p');
                    message.className = 'text-sm text-muted';
                    message.textContent = error.code === 'session'
                        ? 'Sign in again, then reopen this action.'
                        : 'Check your connection and try again, or open the full page to continue.';
                    wrapper.append(message);

                    const actions = document.createElement('div');
                    actions.className = 'flex flex-wrap gap-2';

                    const retry = document.createElement('button');
                    retry.type = 'button';
                    retry.className = 'ui-btn ui-btn-primary';
                    retry.textContent = 'Retry';
                    retry.addEventListener('click', () => void loadModalContent(dialog, trigger));
                    actions.append(retry);

                    const pageUrl = error.code === 'session' && error.loginUrl
                        ? error.loginUrl
                        : fallbackUrl;
                    if (pageUrl) {
                        const fullPage = document.createElement('a');
                        fullPage.className = 'ui-btn ui-btn-secondary';
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
                    content.innerHTML = '<p class="p-5 text-sm text-muted">Loading…</p>';

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

                        const responseBody = await response.text();

                        if (dialog.modalRequestId !== requestId) {
                            return;
                        }

                        content.innerHTML = responseBody;
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

                const reloadModalFragment = (dialog, fragmentUrl, form = null) => {
                    const trigger = dialog?.modalTrigger;

                    if (! trigger || ! fragmentUrl) {
                        return;
                    }

                    const url = new URL(fragmentUrl, window.location.href);

                    if (url.origin !== window.location.origin) {
                        return;
                    }

                    if (form) {
                        for (const [name, value] of new FormData(form).entries()) {
                            if (typeof value !== 'string' || value === '') {
                                url.searchParams.delete(name);
                            } else {
                                url.searchParams.set(name, value);
                            }
                        }
                    }

                    trigger.dataset.modalContentUrl = url.href;
                    dialog.dataset.modalContentLoaded = 'false';
                    delete dialog.dataset.modalContentUrl;
                    void loadModalContent(dialog, trigger);
                };

                document.addEventListener('submit', (event) => {
                    const form = event.target instanceof HTMLFormElement
                        ? event.target.closest('form[data-modal-fragment-form]')
                        : null;
                    const dialog = form?.closest('dialog[data-modal-sheet]');

                    if (! form || ! dialog?.open || form.method.toUpperCase() !== 'GET') {
                        return;
                    }

                    event.preventDefault();
                    reloadModalFragment(dialog, form.dataset.modalFragmentUrl, form);
                });

                document.addEventListener('click', (event) => {
                    const target = event.target instanceof Element ? event.target : null;
                    const link = target?.closest('a[data-modal-fragment-link]');
                    const dialog = link?.closest('dialog[data-modal-sheet]');

                    if (! link || ! dialog?.open) {
                        return;
                    }

                    event.preventDefault();
                    reloadModalFragment(dialog, link.dataset.modalFragmentUrl);
                });

                const cleanModalUrl = () => {
                    const url = new URL(window.location.href);

                    if (! url.searchParams.has('dialog')) {
                        return;
                    }

                    url.searchParams.delete('dialog');
                    window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
                };

                const modalTriggerUrl = (trigger) => {
                    if (! (trigger instanceof HTMLAnchorElement)) {
                        return new URL(window.location.href);
                    }

                    return new URL(trigger.dataset.modalHistoryUrl || trigger.href, window.location.href);
                };

                const bindModalCloseButtons = (dialog) => {
                    dialog.querySelectorAll('[data-modal-close]').forEach((closeButton) => {
                        if (closeButton.dataset.modalCloseBound === 'true') {
                            return;
                        }

                        closeButton.dataset.modalCloseBound = 'true';
                        closeButton.addEventListener('click', (event) => {
                            event.preventDefault();
                            if (dialog.open) {
                                dialog.close('cancel');
                            }
                        });
                    });
                };

                document.addEventListener('click', (event) => {
                    const target = event.target instanceof Element ? event.target : null;
                    const cancel = target?.closest('[data-modal-cancel]');
                    const dialog = cancel?.closest('dialog[data-modal-sheet]');

                    if (! cancel || ! dialog?.open || typeof dialog.close !== 'function') {
                        return;
                    }

                    event.preventDefault();
                    dialog.close('cancel');
                });

                const initialiseModals = () => {
                    document.querySelectorAll('dialog[data-modal-sheet]').forEach(bindModalCloseButtons);
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
                                const url = modalTriggerUrl(trigger);

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
                            const triggerUrl = modalTriggerUrl(trigger);
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
                    const currentUrl = new URL(window.location.href);
                    const openTarget = [...document.querySelectorAll('[data-modal-trigger]')]
                        .find((trigger) => {
                            if (! (trigger instanceof HTMLAnchorElement)) {
                                return false;
                            }

                            const triggerUrl = modalTriggerUrl(trigger);

                            return triggerUrl.pathname === currentUrl.pathname
                                && triggerUrl.search === currentUrl.search
                                && triggerUrl.hash === currentUrl.hash;
                        });

                    document.querySelectorAll('dialog[data-modal-history][open]').forEach((dialog) => {
                        if (dialog !== (openTarget ? document.getElementById(openTarget.dataset.modalTrigger) : null)) {
                            dialog.dataset.modalHistory = 'popped';
                            dialog.close('history');
                        }
                    });

                    if (! openTarget) {
                        syncModalScrollLock();
                        return;
                    }

                    const dialog = document.getElementById(openTarget.dataset.modalTrigger);

                    if (! dialog || dialog.open || typeof dialog.showModal !== 'function') {
                        return;
                    }

                    dialog.showModal();
                    dialog.modalTrigger = openTarget;
                    dialog.dataset.modalHistory = 'pushed';
                    openTarget.setAttribute('aria-expanded', 'true');
                    syncModalScrollLock();
                    void loadModalContent(dialog, openTarget);
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
