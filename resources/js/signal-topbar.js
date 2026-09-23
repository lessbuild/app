const openMenus = () => [...document.querySelectorAll('details[data-signal-menu][open]')];

document.addEventListener('click', (event) => {
  openMenus().forEach((menu) => {
    if (!menu.contains(event.target)) {
      menu.open = false;
    }
  });
});

document.addEventListener('keydown', (event) => {
  if (event.key !== 'Escape') return;

  const menu = openMenus().at(-1);
  if (!menu) return;

  event.preventDefault();
  menu.open = false;
  menu.querySelector('summary')?.focus();
});

const palette = document.querySelector('[data-signal-command-palette]');
const paletteInput = palette?.querySelector('[data-signal-command-input]');
const paletteItems = [...(palette?.querySelectorAll('[data-signal-command-item]') ?? [])];
const emptyMessage = palette?.querySelector('[data-signal-command-empty]');
let paletteTrigger = null;

const closePalette = () => {
  if (palette?.open) palette.close();
};

document.addEventListener('click', (event) => {
  const trigger = event.target instanceof Element
    ? event.target.closest('[data-signal-command-open]')
    : null;

  if (!trigger || !palette || typeof palette.showModal !== 'function') return;

  event.preventDefault();
  paletteTrigger = trigger;
  paletteInput.value = '';
  paletteItems.forEach((item) => { item.hidden = false; });
  if (emptyMessage) emptyMessage.hidden = true;
  palette.showModal();
  paletteInput.focus();
});

paletteInput?.addEventListener('input', () => {
  const query = paletteInput.value.trim().toLowerCase();
  let visibleCount = 0;

  paletteItems.forEach((item) => {
    const match = query === '' || item.dataset.search.includes(query);
    item.hidden = !match;
    if (match) visibleCount += 1;
  });

  if (emptyMessage) emptyMessage.hidden = visibleCount > 0;
});

palette?.addEventListener('click', (event) => {
  if (event.target === palette) closePalette();
});

palette?.addEventListener('close', () => {
  const trigger = paletteTrigger;
  paletteTrigger = null;
  if (trigger?.isConnected) trigger.focus();
});

document.addEventListener('keydown', (event) => {
  if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k' && palette) {
    event.preventDefault();
    if (palette.open) {
      closePalette();
      return;
    }

    document.querySelector('[data-signal-command-open]')?.click();
  }
});
