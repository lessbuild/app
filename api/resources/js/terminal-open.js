// Size a new terminal to the window before the "Open terminal" form is sent (the shell keeps that size).
document.addEventListener('submit', (event) => {
    const form = event.target.closest?.('[data-terminal-open]');
    if (!form) return;
    const columns = Math.floor((Math.min(window.innerWidth, 1400) - 96) / 8.5);
    const rows = Math.floor((window.innerHeight - 320) / 17);
    form.querySelector('[name="columns"]').value = String(Math.max(20, Math.min(240, columns)));
    form.querySelector('[name="rows"]').value = String(Math.max(5, Math.min(100, rows)));
});
