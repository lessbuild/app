// Help centre search: filter the guides as you type, hiding groups with nothing left.
const search = document.querySelector('[data-help-search]');

if (search) {
    const groups = [...document.querySelectorAll('[data-help-group]')];
    const empty = document.querySelector('[data-help-empty]');

    search.addEventListener('input', () => {
        const words = search.value.toLowerCase().split(/\s+/).filter(Boolean);
        let shown = 0;
        groups.forEach((group) => {
            let visible = 0;
            group.querySelectorAll('[data-help-guide]').forEach((guide) => {
                const match = words.every((word) => guide.dataset.helpText.includes(word));
                guide.hidden = !match;
                if (match) visible++;
            });
            group.hidden = visible === 0;
            shown += visible;
        });
        if (empty) empty.hidden = shown > 0;
    });
}
