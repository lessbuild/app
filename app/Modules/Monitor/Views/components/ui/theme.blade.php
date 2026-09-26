<meta name="theme-color" content="#f4f7fb">
<script>
    (() => {
        let preference;
        try { preference = localStorage.getItem('beacon-theme'); } catch {}
        const dark = preference === 'dark' || (preference !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.classList.toggle('dark', dark);
        document.documentElement.classList.toggle('light', !dark);
    })();
</script>
