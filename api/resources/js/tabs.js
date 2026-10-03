// Tabs ([data-tabs] with role="tab" buttons whose aria-controls names their panel): click or use the arrow, Home and
// End keys to switch. Without JavaScript the first panel shows.
document.querySelectorAll('[data-tabs]').forEach((container) => {
    const tabs = [...container.querySelectorAll('[role="tab"]')];
    const select = (tab, focus) => {
        tabs.forEach((other) => {
            const selected = other === tab;
            other.setAttribute('aria-selected', selected ? 'true' : 'false');
            other.tabIndex = selected ? 0 : -1;
            const panel = document.getElementById(other.getAttribute('aria-controls'));
            if (panel) panel.hidden = !selected;
        });
        if (focus) tab.focus();
    };
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => select(tab, false));
        tab.addEventListener('keydown', (event) => {
            const next = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: tabs.length - 1 }[event.key];
            if (next === undefined) return;
            event.preventDefault();
            select(tabs[(next + tabs.length) % tabs.length], true);
        });
    });
});

// Forms that submit when a choice changes ([data-autosubmit] on the control or its field).
document.addEventListener('change', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (target?.closest('[data-autosubmit]') && target.form) target.form.requestSubmit();
});
