export async function copyText(value, feedback) {
  try { await navigator.clipboard.writeText(value); if (feedback) feedback.textContent = 'Copied'; }
  catch (_) {
    const host = feedback?.closest('details, [data-copy-host]');
    const code = host?.querySelector('pre, textarea');
    if (code) {
      if (code.tagName === 'TEXTAREA') { code.focus(); code.select(); }
      else { const range = document.createRange(); range.selectNodeContents(code); const selection = window.getSelection(); selection?.removeAllRanges(); selection?.addRange(range); }
    }
    if (feedback) feedback.textContent = code ? 'Selected — copy now' : 'Clipboard unavailable';
  }
  if (feedback) window.setTimeout(() => { feedback.textContent = 'Copy'; }, 1400);
}

export function initComponentSearch() {
  const input = document.querySelector('[data-component-search]');
  if (!input) return;
  const items = [...document.querySelectorAll('[data-component-item]')];
  const empty = document.querySelector('[data-component-empty]');
  const count = document.querySelector('[data-component-count]');
  const filter = () => {
    const query = input.value.trim().toLowerCase();
    let visible = 0;
    items.forEach((item) => {
      const match = !query || (item.dataset.name || item.textContent).toLowerCase().includes(query);
      item.classList.toggle('hidden', !match);
      if (match) visible += 1;
    });
    if (count) count.textContent = String(visible);
    empty?.classList.toggle('hidden', visible !== 0);
  };
  input.addEventListener('input', filter);
  filter();
}

export function initTemplateSearch() {
  const input = document.querySelector('[data-template-search]');
  const type = document.querySelector('[data-template-type]');
  if (!input) return;
  const items = [...document.querySelectorAll('[data-template-item]')];
  const empty = document.querySelector('[data-template-empty]');
  const count = document.querySelector('[data-template-count]');
  const filter = () => {
    const query = input.value.trim().toLowerCase();
    const selected = type?.value || 'all';
    let visible = 0;
    items.forEach((item) => { const matchesQuery = !query || (item.dataset.templateName || item.textContent).toLowerCase().includes(query); const matchesType = selected === 'all' || item.dataset.templateTypeValue === selected; const match = matchesQuery && matchesType; item.classList.toggle('hidden', !match); if (match) visible += 1; });
    if (count) count.textContent = String(visible);
    empty?.classList.toggle('hidden', visible !== 0);
  };
  input.addEventListener('input', filter);
  type?.addEventListener('change', filter);
  filter();
}

export function initCommandPalette() {
  const dialog = document.querySelector('[data-command-dialog]');
  const input = dialog?.querySelector('[data-command-search]');
  if (!dialog || !input) return;
  const items = [...dialog.querySelectorAll('[data-command-item]')];
  let returnFocus = null;
  const filter = () => {
    const query = input.value.trim().toLowerCase();
    let first = null;
    items.forEach((item) => { const haystack = `${item.textContent} ${item.dataset.commandKeywords || ''}`.toLowerCase(); const match = !query || haystack.includes(query); item.classList.toggle('hidden', !match); if (!first && match) first = item; });
    dialog.querySelector('[data-command-empty]')?.classList.toggle('hidden', Boolean(first));
  };
  input.addEventListener('input', filter);
  dialog.querySelectorAll('a[data-command-item]').forEach((link) => link.addEventListener('click', () => dialog.close()));
  const moveFocus = (direction) => { const visible = items.filter((item) => !item.classList.contains('hidden')); if (!visible.length) return; const currentIndex = visible.indexOf(document.activeElement); const index = currentIndex < 0 ? -1 : currentIndex; const next = direction === 'first' ? 0 : direction === 'last' ? visible.length - 1 : (index + direction + visible.length) % visible.length; visible[next].focus(); };
  input.addEventListener('keydown', (event) => { if (event.key === 'ArrowDown') { event.preventDefault(); moveFocus(1); } });
  dialog.addEventListener('keydown', (event) => { if (event.key === 'ArrowDown') { event.preventDefault(); moveFocus(1); } else if (event.key === 'ArrowUp') { event.preventDefault(); moveFocus(-1); } else if (event.key === 'Home') { event.preventDefault(); moveFocus('first'); } else if (event.key === 'End') { event.preventDefault(); moveFocus('last'); } });
  dialog.addEventListener('close', () => { input.value = ''; filter(); returnFocus?.focus(); returnFocus = null; });
  filter();
}

export function initGlobalCommandPalette() {
  const dialog = document.querySelector('[data-global-command-dialog]');
  const input = dialog?.querySelector('[data-global-command-search]');
  if (!dialog || !input) return;
  const items = [...dialog.querySelectorAll('[data-global-command-item]')];
  let returnFocus = null;
  const visibleItems = () => items.filter((item) => !item.classList.contains('hidden'));
  const filter = () => {
    const query = input.value.trim().toLowerCase();
    let first = null;
    items.forEach((item) => { const haystack = `${item.textContent} ${item.dataset.commandKeywords || ''}`.toLowerCase(); const match = !query || haystack.includes(query); item.classList.toggle('hidden', !match); if (!first && match) first = item; });
    dialog.querySelector('[data-global-command-empty]')?.classList.toggle('hidden', Boolean(first));
  };
  const focusItem = (direction) => { const visible = visibleItems(); if (!visible.length) return; const current = visible.indexOf(document.activeElement); const index = direction === 'first' ? 0 : direction === 'last' ? visible.length - 1 : (Math.max(current, -1) + direction + visible.length) % visible.length; visible[index]?.focus(); };
  const open = (trigger = document.activeElement) => { returnFocus = trigger; input.value = ''; filter(); if (!dialog.open) dialog.showModal(); window.requestAnimationFrame(() => input.focus()); };
  document.querySelectorAll('[data-global-command-open]').forEach((button) => button.addEventListener('click', () => open(button)));
  dialog.querySelector('[data-global-command-close]')?.addEventListener('click', () => dialog.close());
  dialog.querySelectorAll('a[data-global-command-item]').forEach((link) => link.addEventListener('click', () => dialog.close()));
  input.addEventListener('input', filter);
  input.addEventListener('keydown', (event) => { if (event.key === 'ArrowDown') { event.preventDefault(); focusItem(1); } else if (event.key === 'Enter') { event.preventDefault(); focusItem('first'); document.activeElement?.click(); } });
  dialog.addEventListener('keydown', (event) => { if (event.key === 'ArrowDown') { event.preventDefault(); focusItem(1); } else if (event.key === 'ArrowUp') { event.preventDefault(); focusItem(-1); } else if (event.key === 'Home') { event.preventDefault(); focusItem('first'); } else if (event.key === 'End') { event.preventDefault(); focusItem('last'); } });
  document.addEventListener('keydown', (event) => {
    const target = event.target;
    const editing = target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); open(document.activeElement); return; }
    if (event.key === '/' && !editing && !event.metaKey && !event.ctrlKey && !event.altKey) { event.preventDefault(); open(document.activeElement); }
  });
  dialog.addEventListener('close', () => { input.value = ''; filter(); returnFocus?.focus(); returnFocus = null; });
  filter();
}

export function initCopyButtons() {
  document.querySelectorAll('[data-copy-value]').forEach((button) => button.addEventListener('click', () => copyText(button.dataset.copyValue, button.querySelector('[data-copy-label]') || button)));
}
