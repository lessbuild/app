import { initWorkspaceNavigation } from './navigation.js';

const initialiseMonitor = () => {
    initWorkspaceNavigation();

    document.querySelectorAll('[data-copy-value], [data-copy-target]').forEach((button) => {
        button.addEventListener('click', async () => {
            const target = button.dataset.copyTarget ? document.getElementById(button.dataset.copyTarget) : null;
            const value = target ? (target.value ?? target.textContent) : button.dataset.copyValue;

            if (! value) {
                return;
            }

            const label = button.querySelector('[data-copy-label]');
            let copied = false;

            try {
                if (navigator.clipboard?.writeText) {
                    await navigator.clipboard.writeText(value);
                    copied = true;
                }
            } catch {
                copied = false;
            }

            if (!copied && target) {
                if (typeof target.select === 'function') {
                    target.focus();
                    target.select();
                } else {
                    const selection = window.getSelection();
                    const range = document.createRange();
                    range.selectNodeContents(target);
                    selection?.removeAllRanges();
                    selection?.addRange(range);
                }
            }

            if (label) {
                const original = label.textContent;
                label.textContent = copied ? 'Copied' : 'Selected — copy manually';
                window.setTimeout(() => {
                    label.textContent = original;
                }, 1400);
            }
        });
    });

    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('[data-connection-watch]').forEach((panel) => {
        const initialCount = Number(panel.dataset.initialCount);
        const status = panel.querySelector('[data-connection-status]');
        const button = panel.querySelector('[data-check-connection]');
        let checking = false;
        let suspended = false;
        let delay = 5000;
        let timer;
        let requestController;

        const check = async () => {
            if (checking || suspended) return;
            window.clearTimeout(timer);
            if (document.visibilityState === 'hidden') {
                timer = window.setTimeout(check, delay);
                return;
            }

            checking = true;
            button.disabled = true;
            const controller = new AbortController();
            requestController = controller;
            const timeout = window.setTimeout(() => controller.abort(), 10000);

            try {
                const response = await fetch(panel.dataset.connectionUrl, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal: controller.signal,
                });
                if (!response.ok) throw new Error('Connection check unavailable');
                const { data } = await response.json();
                panel.querySelector('[data-connection-count]').textContent = Number(data.event_count).toLocaleString();
                panel.querySelector('[data-connection-time]').textContent = data.last_received_at ?? 'Not yet';
                status.textContent = data.state === 'paused'
                    ? 'Ingestion is paused. Resume it in environment settings.'
                    : data.active_tokens === 0
                        ? 'No active tokens. Create a token to connect your collector.'
                        : data.event_count > initialCount
                            ? 'Connection verified: a new event finished processing. Refresh to inspect it.'
                            : 'Waiting for a processed event. Send the request below, then check Ingestion diagnostics if it stays queued.';
                delay = 5000;
            } catch {
                status.textContent = 'Connection check unavailable. Retrying shortly; you may need to sign in again.';
                delay = Math.min(delay * 2, 30000);
            } finally {
                window.clearTimeout(timeout);
                requestController = null;
                checking = false;
                button.disabled = false;
                if (!suspended) {
                    timer = window.setTimeout(check, delay);
                }
            }
        };

        button.addEventListener('click', check);
        timer = window.setTimeout(check, delay);
        window.addEventListener('pagehide', () => {
            suspended = true;
            window.clearTimeout(timer);
            requestController?.abort();
        });
        window.addEventListener('pageshow', () => {
            suspended = false;
            check();
        });
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialiseMonitor, { once: true });
} else {
    initialiseMonitor();
}
