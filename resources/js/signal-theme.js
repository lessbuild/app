const signalThemeRoot = document.documentElement;
const signalThemeNamespace = signalThemeRoot.dataset.storageNamespace || 'buildpusher';

/* Keep explicit theme overrides predictable even when a caller adds the
 * opposite class directly. The normal controller toggles the classes
 * exclusively, but this also protects browser integrations and progressive
 * enhancement code that uses classList.add(). */
const signalThemeClassObserver = new MutationObserver((mutations) => {
  mutations.forEach((mutation) => {
    const previous = new Set((mutation.oldValue || '').split(/\s+/).filter(Boolean));
    const hasDark = signalThemeRoot.classList.contains('dark');
    const hasLight = signalThemeRoot.classList.contains('light');

    if (hasDark && hasLight) {
      const addedTheme = ! previous.has('dark') ? 'dark' : 'light';
      const existingTheme = addedTheme === 'dark' ? 'light' : 'dark';

      signalThemeRoot.dataset.signalThemeRestore = existingTheme;
      signalThemeRoot.classList.remove(existingTheme);

      return;
    }

    if (! hasDark && ! hasLight && signalThemeRoot.dataset.signalThemeRestore) {
      signalThemeRoot.classList.add(signalThemeRoot.dataset.signalThemeRestore);
      delete signalThemeRoot.dataset.signalThemeRestore;
    }
  });
});

signalThemeClassObserver.observe(signalThemeRoot, {
  attributeFilter: ['class'],
  attributeOldValue: true,
  attributes: true,
});

const syncSignalThemeControls = () => {
  const dark = signalThemeRoot.classList.contains('dark');

  document.querySelectorAll('[data-theme-toggle]').forEach((control) => {
    control.setAttribute('aria-pressed', dark ? 'true' : 'false');
    control.setAttribute('aria-label', dark ? 'Use light theme' : 'Use dark theme');
    const icon = control.querySelector('[data-theme-icon]');
    if (icon) {
      icon.textContent = dark ? '☀' : '☾';
    }
  });

  const themeColor = document.querySelector('[data-theme-color]');
  themeColor?.setAttribute('content', dark ? '#17191c' : '#f4f7fb');
};

const setSignalAppearance = (appearance) => {
  if (! ['light', 'dark'].includes(appearance)) return;

  signalThemeRoot.dataset.appearance = appearance;
  signalThemeRoot.classList.toggle('dark', appearance === 'dark');
  signalThemeRoot.classList.toggle('light', appearance !== 'dark');

  try {
    localStorage.setItem(`${signalThemeNamespace}-appearance`, appearance);
  } catch (_) {}

  syncSignalThemeControls();
};

document.addEventListener('DOMContentLoaded', () => {
  document.addEventListener('click', (event) => {
    const control = event.target.closest('[data-theme-toggle]');
    if (! control) return;

    setSignalAppearance(signalThemeRoot.classList.contains('dark') ? 'light' : 'dark');
  });

  syncSignalThemeControls();
});

window.matchMedia?.('(prefers-color-scheme: dark)').addEventListener('change', (event) => {
  if (signalThemeRoot.dataset.appearance === 'system') {
    signalThemeRoot.classList.toggle('dark', event.matches);
    signalThemeRoot.classList.toggle('light', ! event.matches);
    syncSignalThemeControls();
  }
});
