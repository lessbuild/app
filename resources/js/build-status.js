// A deploy, live: while it runs, poll its status endpoint and update the badge, the stages and the log in place,
// keeping the log scrolled to the end while you're reading the end. Once it finishes, reload once for the next actions.
const POLL_MS = 2000;

document.querySelectorAll('[data-build-status]').forEach((card) => {
    const url = card.dataset.buildStatus;
    const badge = card.querySelector('[data-build-status-badge]');
    const stages = [...document.querySelectorAll('[data-build-stage]')];
    const log = document.querySelector('[data-build-log]');
    const logEmpty = document.querySelector('[data-build-log-empty]');
    let timer = null;

    const render = (data) => {
        if (badge && badge.innerHTML.trim() !== data.badge.trim()) badge.innerHTML = data.badge;
        stages.forEach((stage) => {
            const done = data.stage > Number(stage.dataset.buildStage);
            stage.classList.toggle('text-ink', done);
            stage.classList.toggle('text-muted', !done);
            const mark = stage.querySelector('[data-build-stage-mark]');
            if (mark) mark.textContent = done ? '✓' : '·';
        });
        if (log && data.log !== null) {
            const code = log.querySelector('code') ?? log;
            if (code.textContent !== data.log) {
                const following = log.scrollHeight - log.scrollTop - log.clientHeight < 40;
                code.textContent = data.log;
                log.classList.remove('hidden');
                logEmpty?.remove();
                if (following) log.scrollTop = log.scrollHeight;
            }
        }
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

    document.addEventListener('visibilitychange', () => {
        if (document.hidden && timer !== null) {
            clearTimeout(timer);
            timer = null;
        } else if (!document.hidden && timer === null) {
            poll();
        }
    });

    if (log) log.scrollTop = log.scrollHeight;
    schedule();
});
