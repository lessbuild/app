function focusableIn(element) { return [...element.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]; }

export function initDrawer(selector, toggleSelector, closeAt = 768) {
  const drawer = document.querySelector(selector); if (!drawer) return; const toggles = [...document.querySelectorAll(toggleSelector)]; let lastTrigger = null;
  const close = (restore = true) => { drawer.classList.add('hidden'); drawer.setAttribute('aria-hidden', 'true'); document.body.classList.remove('overflow-hidden'); document.documentElement.classList.remove('drawer-open'); toggles.forEach((button) => button.setAttribute('aria-expanded', 'false')); if (restore) lastTrigger?.focus(); };
  const open = (trigger) => { lastTrigger = trigger; drawer.classList.remove('hidden'); drawer.setAttribute('aria-hidden', 'false'); drawer.setAttribute('aria-modal', 'true'); document.body.classList.add('overflow-hidden'); document.documentElement.classList.add('drawer-open'); toggles.forEach((button) => button.setAttribute('aria-expanded', 'true')); window.requestAnimationFrame(() => focusableIn(drawer)[0]?.focus()); };
  toggles.forEach((toggle) => toggle.addEventListener('click', () => drawer.classList.contains('hidden') ? open(toggle) : close()));
  drawer.addEventListener('click', (event) => { if (event.target === drawer) close(); }); drawer.querySelectorAll('a').forEach((link) => link.addEventListener('click', close));
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !drawer.classList.contains('hidden')) { close(); return; } if (event.key !== 'Tab' || drawer.classList.contains('hidden')) return; const nodes = focusableIn(drawer); if (!nodes.length) return; const first = nodes[0]; const last = nodes[nodes.length - 1]; if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); } else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); } });
  window.addEventListener('resize', () => { if (window.matchMedia(`(min-width: ${closeAt}px)`).matches && !drawer.classList.contains('hidden')) close(false); });
}

export function initTabs() {
  document.querySelectorAll('[data-tabs]').forEach((group) => {
    const tabs = [...group.querySelectorAll('[data-tab]')]; const panels = [...group.querySelectorAll('[data-tab-panel]')];
    const show = (name, moveFocus = false) => { tabs.forEach((tab) => { const active = tab.dataset.tab === name; tab.setAttribute('aria-selected', String(active)); tab.setAttribute('tabindex', active ? '0' : '-1'); tab.classList.toggle('border-primary', active); tab.classList.toggle('text-primary', active); tab.classList.toggle('border-transparent', !active); tab.classList.toggle('text-muted', !active); }); panels.forEach((panel) => { const active = panel.dataset.tabPanel === name; panel.classList.toggle('hidden', !active); panel.setAttribute('aria-hidden', String(!active)); }); if (moveFocus) tabs.find((tab) => tab.dataset.tab === name)?.focus(); };
    tabs.forEach((tab, index) => { tab.addEventListener('click', () => show(tab.dataset.tab)); tab.addEventListener('keydown', (event) => { if (!['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(event.key)) return; event.preventDefault(); const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length; show(tabs[next].dataset.tab, true); }); });
    const initial = tabs.find((tab) => tab.getAttribute('aria-selected') === 'true') || tabs[0]; if (initial) show(initial.dataset.tab);
  });
}

export function initDialogs() {
  document.querySelectorAll('[data-dialog-open]').forEach((button) => button.addEventListener('click', () => { const dialog = document.getElementById(button.dataset.dialogOpen); if (!dialog?.showModal) return; dialog.dataset.returnFocus = button.id || ''; dialog.showModal(); dialog.querySelector('button, input, [href]')?.focus(); }));
  document.querySelectorAll('dialog').forEach((dialog) => dialog.addEventListener('close', () => { const trigger = dialog.dataset.returnFocus ? document.getElementById(dialog.dataset.returnFocus) : document.querySelector('[data-dialog-open="' + dialog.id + '"]'); trigger?.focus(); }));
  document.querySelectorAll('[data-dialog-close]').forEach((button) => button.addEventListener('click', () => button.closest('dialog')?.close()));
}
