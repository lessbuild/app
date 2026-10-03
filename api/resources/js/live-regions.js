// Live regions: <section id="…" data-live-region data-live-interval="10000"> re-fetches its own page every so often and
// swaps in the new copy of that region when it changed, so lists and timelines stay current without a reload. It
// waits while the tab is hidden, while focus or an open <details>/<dialog> is inside the region, or a modal is open.
const regions = [...document.querySelectorAll('[data-live-region][id]')];

if (regions.length > 0) {
    const interval = Math.max(3000, Math.min(...regions.map((region) => Number(region.dataset.liveInterval) || 10000)));
    let timer = null;
    let busy = false;

    const idle = (region) => !region.contains(document.activeElement)
        && !region.querySelector('details[open], dialog[open]')
        && !document.querySelector('dialog[open]');

    const refresh = async () => {
        timer = null;
        if (busy) return schedule();
        busy = true;
        try {
            const response = await fetch(window.location.href, { headers: { Accept: 'text/html', 'X-Live-Region': '1' }, credentials: 'same-origin' });
            if (response.ok && !response.redirected) {
                const page = new DOMParser().parseFromString(await response.text(), 'text/html');
                regions.forEach((region) => {
                    const fresh = page.getElementById(region.id);
                    if (fresh && idle(region) && fresh.innerHTML !== region.innerHTML) {
                        region.innerHTML = fresh.innerHTML;
                        region.dispatchEvent(new CustomEvent('live-region:updated', { bubbles: true }));
                    }
                });
            }
        } catch {
            // Try again next time.
        } finally {
            busy = false;
        }
        schedule();
    };

    const schedule = () => {
        if (timer === null && !document.hidden) timer = setTimeout(refresh, interval);
    };

    document.addEventListener('visibilitychange', () => {
        if (document.hidden && timer !== null) {
            clearTimeout(timer);
            timer = null;
        } else if (!document.hidden && timer === null) {
            refresh();
        }
    });

    schedule();
}
