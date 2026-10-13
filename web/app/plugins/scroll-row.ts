// `v-scroll-row` for rows of tabs or links that scroll sideways on small screens: the side hiding more fades out
// (data-more="start", "end" or "both", styled in acme.css) so it's clear the row goes on, and the current item
// (aria-current or aria-selected) is scrolled into view when the row appears and whenever it changes.

type ScrollRow = HTMLElement & { scrollRow?: { measure: () => void; current: Element | null; observer: ResizeObserver | null } };

/** Mark which ends of the row hide more. */
function measure(row: HTMLElement): void {
    const start = row.scrollLeft > 2;
    const end = row.scrollLeft + row.clientWidth < row.scrollWidth - 2;
    const more = start && end ? 'both' : start ? 'start' : end ? 'end' : '';
    if (more === '') {
        row.removeAttribute('data-more');
    } else if (row.getAttribute('data-more') !== more) {
        row.setAttribute('data-more', more);
    }
}

/** Scroll the row so its current item sits in the middle, when it isn't already fully in view. */
function reveal(row: ScrollRow): void {
    const current = row.querySelector('[aria-current="page"], [aria-current="true"], [aria-selected="true"]');
    if (row.scrollRow) {
        row.scrollRow.current = current;
    }
    if (current instanceof HTMLElement) {
        const rowBox = row.getBoundingClientRect();
        const box = current.getBoundingClientRect();
        if (box.left < rowBox.left || box.right > rowBox.right) {
            row.scrollLeft += box.left - rowBox.left - (rowBox.width - box.width) / 2;
        }
    }
    measure(row);
}

export default defineNuxtPlugin((nuxtApp) => {
    nuxtApp.vueApp.directive<ScrollRow>('scroll-row', {
        mounted(row) {
            const update = () => measure(row);
            row.scrollRow = { measure: update, current: null, observer: typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(update) };
            row.addEventListener('scroll', update, { passive: true });
            row.scrollRow.observer?.observe(row);
            reveal(row);
        },
        updated(row) {
            // A different current item (the route changed, a tab was chosen) scrolls into view; otherwise just re-measure.
            const current = row.querySelector('[aria-current="page"], [aria-current="true"], [aria-selected="true"]');
            if (current !== row.scrollRow?.current) {
                reveal(row);
            } else {
                measure(row);
            }
        },
        beforeUnmount(row) {
            if (row.scrollRow) {
                row.removeEventListener('scroll', row.scrollRow.measure);
                row.scrollRow.observer?.disconnect();
            }
        },
        getSSRProps: () => ({}),
    });
});
