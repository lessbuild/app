// The troubleshooting terminal: xterm.js in the page, relayed to the server through the app.
// Keystrokes are posted in small batches; output is polled and each poll acknowledges what arrived.
import { Terminal } from '@xterm/xterm';
import '@xterm/xterm/css/xterm.css';

const root = document.querySelector('[data-terminal]');

if (root) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const status = root.querySelector('[data-terminal-status]');
    const terminal = new Terminal({
        cols: Number(root.dataset.columns),
        rows: Number(root.dataset.rows),
        cursorBlink: true,
        convertEol: false,
        fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
        fontSize: 14,
        theme: { background: '#0b1020' },
    });
    terminal.open(root.querySelector('[data-terminal-screen]'));
    terminal.focus();

    let after = 0;
    let pending = '';
    let sending = false;
    let open = true;

    const say = (text) => {
        if (status) status.textContent = text;
    };

    const send = async () => {
        if (sending || pending === '' || !open) return;
        sending = true;
        const input = pending.slice(0, 4096);
        pending = pending.slice(input.length);
        try {
            const response = await fetch(root.dataset.inputUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ input }),
            });
            if (!response.ok && response.status !== 429) say(root.dataset.closedText);
        } finally {
            sending = false;
            if (pending !== '') send();
        }
    };

    terminal.onData((data) => {
        pending += data;
        send();
    });

    const poll = async () => {
        if (!open) return;
        try {
            const response = await fetch(`${root.dataset.outputUrl}?after=${after}`, { headers: { Accept: 'application/json' } });
            if (response.ok) {
                const { data } = await response.json();
                for (const frame of data.frames) {
                    terminal.write(frame.data);
                    after = frame.sequence;
                }
                if (data.status === 'connected') say(root.dataset.connectedText);
                if (data.status !== 'connecting' && data.status !== 'connected' && data.frames.length === 0) {
                    open = false;
                    say(`${root.dataset.closedText} (${data.reason ?? data.status})`);
                    terminal.options.disableStdin = true;
                    return;
                }
                setTimeout(poll, data.frames.length > 0 ? 50 : 250);
                return;
            }
            if (response.status === 403 || response.status === 404 || response.status === 409) {
                open = false;
                say(root.dataset.closedText);
                return;
            }
        } catch {
            // A dropped request is retried on the next poll.
        }
        setTimeout(poll, 1000);
    };

    poll();
}
