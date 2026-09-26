{{-- Applies saved appearance before first paint to avoid a flash of the wrong theme. --}}
<script>
(() => {
    const root = document.documentElement;
    const namespace = root.dataset.storageNamespace || 'signal-starter';
    const legacyNamespace = 'buildpusher';
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
    const fallback = (name) => root.dataset[`default${name[0].toUpperCase()}${name.slice(1)}`] || defaults[name];
    const read = (name) => {
        const shared = new URLSearchParams(window.location.search).get(`theme_${name}`);

        if (allowed[name]?.includes(shared)) {
            return shared;
        }

        if (name === 'appearance' && allowed.appearance.includes(root.dataset.themeUserAppearance)) {
            return root.dataset.themeUserAppearance;
        }

        try {
            const stored = localStorage.getItem(`${namespace}-${name}`);

            if (allowed[name]?.includes(stored)) {
                return stored;
            }

            if (name === 'appearance') {
                const legacyAppearance = localStorage.getItem(`${legacyNamespace}-appearance`);

                if (allowed.appearance.includes(legacyAppearance)) {
                    return legacyAppearance;
                }
            }
        } catch (_) {
            return fallback(name);
        }

        return fallback(name);
    };
    const appearance = read('appearance');
    const dark = appearance === 'dark'
        || (appearance === 'system' && window.matchMedia?.('(prefers-color-scheme: dark)').matches);

    root.classList.toggle('dark', dark);
    root.classList.toggle('light', ! dark);
    root.dataset.appearance = appearance;
    root.dataset.preset = read('preset');
    root.dataset.palette = read('palette');
    root.dataset.density = read('density');
    root.dataset.corners = read('corners');
    root.dataset.font = read('font');
    root.dataset.motion = read('motion');
    root.dataset.contrast = read('contrast');
})();
</script>
