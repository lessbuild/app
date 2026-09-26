const signalThemeRoot = document.documentElement;
const signalThemeNamespace = signalThemeRoot.dataset.storageNamespace || 'buildpusher';
let signalThemeSaveInProgress = false;

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
  if (! ['system', 'light', 'dark'].includes(appearance)) return;

  signalThemeRoot.dataset.appearance = appearance;
  const dark = appearance === 'dark'
    || (appearance === 'system' && window.matchMedia?.('(prefers-color-scheme: dark)').matches);
  signalThemeRoot.classList.toggle('dark', dark);
  signalThemeRoot.classList.toggle('light', ! dark);

  try {
    localStorage.setItem(`${signalThemeNamespace}-appearance`, appearance);
  } catch (_) {}

  syncSignalThemeControls();
};

const persistSignalAppearance = async (appearance, previousAppearance) => {
  const url = signalThemeRoot.dataset.themePreferenceUrl;
  const status = document.querySelector('[data-theme-status]');
  const controls = [...document.querySelectorAll('[data-theme-toggle]')];
  const previousDisabled = controls.map((control) => control.disabled);
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

  if (! url || ! csrfToken) {
    setSignalAppearance(previousAppearance);
    if (status) status.textContent = signalThemeRoot.dataset.themeSaveFailedLabel || 'Appearance could not be saved.';

    return;
  }

  signalThemeSaveInProgress = true;
  controls.forEach((control) => {
    control.disabled = true;
    control.setAttribute('aria-busy', 'true');
  });
  if (status) status.textContent = signalThemeRoot.dataset.themeSavingLabel || 'Saving appearance preference.';

  try {
    const response = await fetch(url, {
      method: 'PUT',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
      },
      body: JSON.stringify({ appearance }),
    });
    const result = await response.json();

    if (! response.ok || result.appearance !== appearance) {
      throw new Error('Appearance preference was not saved.');
    }

    signalThemeRoot.dataset.themeUserAppearance = appearance;
    if (status) status.textContent = signalThemeRoot.dataset.themeSavedLabel || 'Appearance preference saved.';
  } catch (_) {
    setSignalAppearance(previousAppearance);
    if (status) status.textContent = signalThemeRoot.dataset.themeSaveFailedLabel || 'Appearance could not be saved.';
  } finally {
    controls.forEach((control, index) => {
      control.disabled = previousDisabled[index];
      control.removeAttribute('aria-busy');
    });
    signalThemeSaveInProgress = false;
  }
};

document.addEventListener('DOMContentLoaded', () => {
  document.addEventListener('click', (event) => {
    const control = event.target.closest('[data-theme-toggle]');
    if (! control || signalThemeSaveInProgress) return;

    const previousAppearance = signalThemeRoot.dataset.appearance || 'system';
    const appearance = signalThemeRoot.classList.contains('dark') ? 'light' : 'dark';
    setSignalAppearance(appearance);

    if (signalThemeRoot.dataset.themePreferenceUrl) {
      void persistSignalAppearance(appearance, previousAppearance);
    }
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
