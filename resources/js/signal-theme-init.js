(() => {
  const root = document.documentElement;
  const namespace = root.dataset.storageNamespace || 'signal-starter';
  const legacyNamespace = 'buildpusher';
  const defaults = {
    preset: 'modern',
    appearance: 'system',
    palette: 'violet',
    density: 'comfortable',
    corners: 'rounded',
    font: 'system',
    motion: 'system',
    contrast: 'default',
  };
  const allowed = {
    preset: ['modern', 'editorial', 'compact', 'noir', 'ocean', 'forest', 'sunset'],
    appearance: ['system', 'light', 'dark'],
    palette: ['violet', 'blue', 'emerald', 'rose', 'neutral', 'indigo', 'teal', 'amber', 'graphite'],
    density: ['compact', 'comfortable'],
    corners: ['subtle', 'soft', 'rounded'],
    font: ['system', 'editorial'],
    motion: ['system', 'reduced'],
    contrast: ['default', 'high'],
  };
  const read = (name) => {
    const shared = new URLSearchParams(window.location.search).get(`theme_${name}`);

    if (allowed[name]?.includes(shared)) return shared;

    if (name === 'appearance' && allowed.appearance.includes(root.dataset.themeUserAppearance)) {
      return root.dataset.themeUserAppearance;
    }

    try {
      const stored = localStorage.getItem(`${namespace}-${name}`);

      if (allowed[name]?.includes(stored)) return stored;

      if (name === 'appearance') {
        const legacyAppearance = localStorage.getItem(`${legacyNamespace}-appearance`);

        if (allowed.appearance.includes(legacyAppearance)) return legacyAppearance;
      }
    } catch (_) {}

    return root.dataset[`default${name[0].toUpperCase()}${name.slice(1)}`] || defaults[name];
  };

  root.dataset.preset = read('preset');
  root.dataset.appearance = read('appearance');
  root.dataset.palette = read('palette');
  root.dataset.density = read('density');
  root.dataset.corners = read('corners');
  root.dataset.font = read('font');
  root.dataset.motion = read('motion');
  root.dataset.contrast = read('contrast');

  const tokenNames = ['ui-primary', 'ui-primary-hover', 'ui-page', 'ui-surface', 'ui-surface-muted', 'ui-line', 'ui-ink', 'ui-muted', 'ui-subtle', 'ui-focus', 'radius-control-value', 'density-control-y'];

  tokenNames.forEach((name) => {
    try {
      const value = localStorage.getItem(`${namespace}-token-${name}`);
      const safe = name.startsWith('ui-') ? /^#[0-9a-f]{6}$/i.test(value || '') : /^(?:0|[0-9]+(?:\.[0-9]+)?)(?:rem|px)$/.test(value || '');

      if (safe) root.style.setProperty(`--${name}`, value);
    } catch (_) {}
  });

  root.classList.toggle('dark', root.dataset.appearance === 'dark' || (root.dataset.appearance === 'system' && window.matchMedia?.('(prefers-color-scheme: dark)').matches));
  root.classList.toggle('light', !root.classList.contains('dark'));
})();
