// Server setup, live: while a server provisions, poll its status endpoint and update the badge, stage, current step,
// progress bar, SSH address, host key and waiting reason in place. Once setup finishes or fails, reload once so the
// page shows what comes next.
const POLL_MS = 3000;

document.querySelectorAll('[data-server-status]').forEach((card) => {
    const url = card.dataset.serverStatus;
    const find = (name) => card.querySelector(`[data-server-status-${name}]`);
    const badge = find('badge');
    const stage = find('stage');
    const step = find('step');
    const stepText = find('step-text');
    const progress = find('progress');
    const waiting = find('waiting');
    const reason = find('reason');
    const ssh = find('ssh');
    const hostKey = find('host-key');
    let timer = null;

    const render = (data) => {
        if (badge && badge.innerHTML.trim() !== data.badge.trim()) badge.innerHTML = data.badge;
        if (stage) stage.textContent = stage.dataset.template.replace('__stage__', data.stage).replace('__final__', data.final_stage);
        if (stepText) stepText.textContent = data.step ?? '';
        if (step) step.hidden = !data.step;
        if (progress) {
            progress.setAttribute('aria-valuenow', data.stage);
            const bar = progress.querySelector('span');
            if (bar) bar.style.width = `${Math.min(100, (data.stage / Math.max(1, data.final_stage)) * 100)}%`;
        }
        if (waiting) waiting.hidden = data.status !== 'waiting_for_ip';
        if (ssh) ssh.textContent = data.ssh;
        if (hostKey) hostKey.textContent = data.host_key ?? '—';
        if (reason) reason.textContent = data.reason ? reason.dataset.template.replace('__reason__', data.reason) : '';
    };

    const poll = async () => {
        timer = null;
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (response.ok) {
                const data = await response.json();
                render(data);
                if (data.finished) {
                    window.location.reload();
                    return;
                }
            }
        } catch {
            // A dropped request is retried on the next poll.
        }
        schedule();
    };

    const schedule = () => {
        if (timer === null && !document.hidden) timer = setTimeout(poll, POLL_MS);
    };

    // Pause while the tab is hidden, and catch up straight away when it's shown again.
    document.addEventListener('visibilitychange', () => {
        if (document.hidden && timer !== null) {
            clearTimeout(timer);
            timer = null;
        } else if (!document.hidden && timer === null) {
            poll();
        }
    });

    if (step && !stepText?.textContent.trim()) step.hidden = true;
    schedule();
});
