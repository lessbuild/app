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
const dynamicResults = palette?.querySelector('[data-signal-command-dynamic-results]');
const searchStatus = palette?.querySelector('[data-signal-command-status]');
const searchUrl = palette?.dataset.signalCommandSearchUrl;
let paletteTrigger = null;
let searchTimer = null;
let searchRequest = null;
let searchSequence = 0;

const closePalette = () => {
  if (palette?.open) palette.close();
};

const updateEmptyState = () => {
  const staticVisible = paletteItems.some((item) => !item.hidden);
  const dynamicVisible = Boolean(dynamicResults?.querySelector('[data-signal-command-dynamic-item]'));
  const searching = palette?.dataset.searching === 'true';
  const unavailable = Boolean(dynamicResults?.querySelector('[data-signal-command-unavailable]'));

  if (emptyMessage) emptyMessage.hidden = staticVisible || dynamicVisible || searching || unavailable;
};

const filterStaticItems = (query) => {
  const normalizedQuery = query.trim().toLocaleLowerCase();

  paletteItems.forEach((item) => {
    item.hidden = normalizedQuery !== '' && !item.dataset.search.includes(normalizedQuery);
  });
};

const makeResultLink = (result, group) => {
  if (!result || typeof result.title !== 'string' || typeof result.url !== 'string') return null;

  let target;
  try {
    target = new URL(result.url, window.location.href);
  } catch {
    return null;
  }

  if (!['http:', 'https:'].includes(target.protocol)) return null;

  const link = document.createElement('a');
  link.href = target.href;
  link.dataset.signalCommandDynamicItem = '';
  link.className = 'flex min-h-11 items-center justify-between gap-3 rounded-control px-3 py-2 text-sm font-bold text-muted hover:bg-surface-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus';

  const content = document.createElement('span');
  content.className = 'min-w-0';
  const title = document.createElement('span');
  title.className = 'block truncate text-ink';
  title.textContent = result.title;
  content.append(title);

  if (typeof result.subtitle === 'string' && result.subtitle !== '') {
    const subtitle = document.createElement('span');
    subtitle.className = 'mt-0.5 block truncate text-xs font-normal text-muted';
    subtitle.textContent = result.subtitle;
    content.append(subtitle);
  }

  const type = document.createElement('span');
  type.className = 'shrink-0 rounded-full border border-line px-2 py-1 text-[10px] font-extrabold uppercase tracking-wide text-subtle';
  type.textContent = typeof result.type === 'string' ? result.type : group.label;
  link.append(content, type);
  link.addEventListener('click', closePalette);

  return link;
};

const renderSearchResults = (payload) => {
  if (!dynamicResults) return;
  dynamicResults.replaceChildren();

  const groups = Array.isArray(payload?.groups) ? payload.groups : [];
  let count = 0;

  groups.forEach((group) => {
    if (typeof group?.label !== 'string' || !Array.isArray(group.results) || group.results.length === 0) return;

    const section = document.createElement('section');
    section.className = 'grid gap-1';
    const heading = document.createElement('p');
    heading.className = 'px-3 pt-2 text-[10px] font-extrabold uppercase tracking-[0.16em] text-subtle';
    heading.textContent = group.label;
    section.append(heading);

    group.results.forEach((result) => {
      const link = makeResultLink(result, group);
      if (!link) return;
      section.append(link);
      count += 1;
    });

    if (section.querySelector('[data-signal-command-dynamic-item]')) dynamicResults.append(section);
  });

  const unavailable = Array.isArray(payload?.unavailable) ? payload.unavailable : [];
  if (unavailable.length > 0) {
    const notice = document.createElement('p');
    notice.dataset.signalCommandUnavailable = '';
    notice.className = 'ui-alert ui-alert--warning text-xs leading-5';
    notice.textContent = `Search could not reach: ${unavailable.map((item) => item.label).filter((label) => typeof label === 'string').join(', ')}. Other results are shown.`;
    dynamicResults.append(notice);
  }

  if (searchStatus) {
    const productCount = groups.filter((group) => Array.isArray(group?.results) && group.results.length > 0).length;
    searchStatus.textContent = `${count} ${count === 1 ? 'result' : 'results'}${productCount > 0 ? ` across ${productCount} ${productCount === 1 ? 'section' : 'sections'}` : ''}${unavailable.length > 0 ? '. Some apps could not be searched.' : '.'}`;
  }

  updateEmptyState();
};

const queueWorkspaceSearch = () => {
  window.clearTimeout(searchTimer);
  searchRequest?.abort();
  searchRequest = null;
  searchSequence += 1;
  const sequence = searchSequence;
  const query = paletteInput?.value.trim() ?? '';

  filterStaticItems(query);
  dynamicResults?.replaceChildren();
  palette.dataset.searching = 'false';

  if (query === '') {
    if (searchStatus) searchStatus.textContent = '';
    updateEmptyState();
    return;
  }

  if (!searchUrl || query.length < 2) {
    if (searchStatus) searchStatus.textContent = query.length < 2 ? 'Enter at least 2 characters to search workspace resources.' : '';
    updateEmptyState();
    return;
  }

  palette.dataset.searching = 'true';
  if (searchStatus) searchStatus.textContent = 'Searching workspace resources…';
  if (dynamicResults) {
    const loading = document.createElement('p');
    loading.dataset.signalCommandLoading = '';
    loading.className = 'px-3 py-3 text-sm text-muted';
    loading.textContent = 'Searching workspace resources…';
    dynamicResults.replaceChildren(loading);
  }
  updateEmptyState();

  searchTimer = window.setTimeout(async () => {
    const controller = new AbortController();
    searchRequest = controller;
    const url = new URL(searchUrl, window.location.href);
    url.searchParams.set('q', query);

    try {
      const response = await fetch(url, {
        signal: controller.signal,
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      });

      if (!response.ok) throw new Error(`Workspace search failed with ${response.status}`);
      const payload = await response.json();
      if (sequence !== searchSequence) return;

      renderSearchResults(payload);
    } catch (error) {
      if (error.name !== 'AbortError' && sequence === searchSequence) {
        if (searchStatus) searchStatus.textContent = 'Workspace search is temporarily unavailable.';
        if (dynamicResults) {
          const notice = document.createElement('p');
          notice.setAttribute('role', 'alert');
          notice.dataset.signalCommandUnavailable = '';
          notice.className = 'px-3 py-3 text-sm text-muted';
          notice.textContent = 'Workspace search is temporarily unavailable.';
          dynamicResults.replaceChildren(notice);
        }
      }
    } finally {
      if (sequence === searchSequence) {
        palette.dataset.searching = 'false';
        searchRequest = null;
        updateEmptyState();
      }
    }
  }, 160);
};

const visiblePaletteLinks = () => [...(palette?.querySelectorAll('a[data-signal-command-item], a[data-signal-command-dynamic-item]') ?? [])]
  .filter((link) => !link.hidden);

document.addEventListener('click', (event) => {
  const trigger = event.target instanceof Element
    ? event.target.closest('[data-signal-command-open]')
    : null;

  if (!trigger || !palette || typeof palette.showModal !== 'function') return;

  event.preventDefault();
  paletteTrigger = trigger;
  searchRequest?.abort();
  searchSequence += 1;
  paletteInput.value = '';
  dynamicResults?.replaceChildren();
  palette.dataset.searching = 'false';
  paletteItems.forEach((item) => { item.hidden = false; });
  if (searchStatus) searchStatus.textContent = '';
  if (emptyMessage) emptyMessage.hidden = true;
  palette.showModal();
  paletteInput.focus();
});

paletteInput?.addEventListener('input', queueWorkspaceSearch);

palette?.addEventListener('keydown', (event) => {
  if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return;

  const links = visiblePaletteLinks();
  if (links.length === 0) return;

  const currentIndex = links.indexOf(document.activeElement);
  const direction = event.key === 'ArrowDown' ? 1 : -1;
  const nextIndex = currentIndex < 0
    ? (direction > 0 ? 0 : links.length - 1)
    : (currentIndex + direction + links.length) % links.length;

  event.preventDefault();
  links[nextIndex]?.focus();
});

palette?.addEventListener('click', (event) => {
  if (event.target === palette || event.target instanceof Element && event.target.closest('a[data-signal-command-item]')) {
    closePalette();
  }
});

palette?.addEventListener('close', () => {
  window.clearTimeout(searchTimer);
  searchRequest?.abort();
  searchRequest = null;
  searchSequence += 1;
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
