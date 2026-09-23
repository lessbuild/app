const root = document.documentElement;
const storedTheme = localStorage.getItem('buildpusher-analytics-theme');

if (storedTheme === 'dark' || (!storedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    root.classList.add('dark');
}

document.addEventListener('click', (event) => {
    const themeToggle = event.target.closest('[data-theme-toggle]');
    if (themeToggle) {
        root.classList.toggle('dark');
        localStorage.setItem('buildpusher-analytics-theme', root.classList.contains('dark') ? 'dark' : 'light');
    }

    const menuToggle = event.target.closest('[data-mobile-menu-toggle]');
    if (menuToggle) {
        const drawer = document.querySelector('[data-mobile-drawer]');
        const open = drawer?.classList.toggle('hidden') === false;
        document.body.classList.toggle('overflow-hidden', open);
        menuToggle.setAttribute('aria-expanded', String(open));
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        const drawer = document.querySelector('[data-mobile-drawer]');
        if (drawer && !drawer.classList.contains('hidden')) {
            drawer.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }
    }
});
