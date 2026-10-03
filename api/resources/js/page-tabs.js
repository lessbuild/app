// Page tabs (x-signal.ui.page-tabs): switch panels in place, keep ?tab= in the address, and support arrow keys.
function activate(tabs, tab, focus) {
    const name = tab.dataset.pageTab;
    tabs.forEach((other) => {
        const selected = other === tab;
        other.setAttribute('aria-selected', selected ? 'true' : 'false');
        other.tabIndex = selected ? 0 : -1;
        if (selected) other.setAttribute('aria-current', 'page');
        else other.removeAttribute('aria-current');
        const panel = document.getElementById(other.getAttribute('aria-controls'));
        if (panel) panel.hidden = !selected;
    });
    const url = new URL(window.location.href);
    url.searchParams.set('tab', name);
    url.hash = '';
    window.history.replaceState(window.history.state, '', url);
    if (focus) tab.focus();
}

document.querySelectorAll('[data-page-tabs]').forEach((nav) => {
    const tabs = Array.from(nav.querySelectorAll('[data-page-tab]'));
    // Tabs whose panel isn't on the page stay ordinary links.
    const switchable = tabs.filter((tab) => document.getElementById(tab.getAttribute('aria-controls')));
    switchable.forEach((tab) => {
        tab.addEventListener('click', (event) => {
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
            event.preventDefault();
            activate(switchable, tab, false);
        });
        tab.addEventListener('keydown', (event) => {
            const index = switchable.indexOf(tab);
            const next = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: switchable.length - 1 }[event.key];
            if (next === undefined) return;
            event.preventDefault();
            activate(switchable, switchable[(next + switchable.length) % switchable.length], true);
        });
    });
});
