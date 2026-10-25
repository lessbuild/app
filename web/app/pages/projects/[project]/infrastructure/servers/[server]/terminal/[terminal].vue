<script setup lang="ts">
import type { TerminalPage } from '~/types/infrastructure';

/**
 * A root shell on a server, in the browser: keystrokes go to the server in small batches and output is fetched as it
 * arrives, through the app. It closes when idle or after its time limit; opening and closing it are audited.
 */
definePageMeta({ layout: 'app', service: 'infrastructure' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<TerminalPage>(() => `/projects/${route.params.project}/infrastructure/servers/${route.params.server}/terminal/${route.params.terminal}`);
const project = computed(() => data.value.overview.project);
const base = computed(() => `/api/app/projects/${project.value.id}/infrastructure/servers/${data.value.server.id}/terminal/${data.value.terminal.id}`);
const screen = ref<HTMLElement | null>(null);
const state = ref(t('Connecting…'));
// Copy and Paste, exposed for the on-screen buttons (phones have no keyboard shortcuts or right-click).
const clipboard = ref<{ copy: () => void; paste: () => void } | null>(null);
let open = true;
let dispose: (() => void) | undefined;

onMounted(async () => {
    if (!data.value.ownBrowser || !data.value.terminal.active || screen.value === null) {
        return;
    }
    const { Terminal } = await import('@xterm/xterm');
    await import('@xterm/xterm/css/xterm.css');
    const terminal = new Terminal({
        cols: data.value.terminal.columns,
        rows: data.value.terminal.rows,
        cursorBlink: true,
        fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
        fontSize: 14,
        theme: { background: '#0b1020' },
    });
    terminal.open(screen.value);
    terminal.focus();

    // Copy the selection, and paste the clipboard, the way a terminal is expected to.
    const copySelection = () => {
        const selection = terminal.getSelection();
        if (selection !== '' && navigator.clipboard) {
            void navigator.clipboard.writeText(selection).catch(() => {});
        }
    };
    const pasteClipboard = () => {
        terminal.focus();
        if (navigator.clipboard?.readText) {
            void navigator.clipboard.readText().then((text) => text && terminal.paste(text)).catch(() => {});
        }
    };
    // On a phone there's no selection to copy, so the Copy button grabs the whole buffer when nothing is selected.
    clipboard.value = {
        copy: () => {
            if (!terminal.hasSelection()) {
                terminal.selectAll();
            }
            copySelection();
            terminal.clearSelection();
        },
        paste: pasteClipboard,
    };
    terminal.attachCustomKeyEventHandler((event) => {
        if (event.type !== 'keydown') {
            return true;
        }
        const key = event.key.toLowerCase();
        // Copy on ⌘C (macOS) or Ctrl/⌘+Shift+C; plain Ctrl+C stays the interrupt.
        if (key === 'c' && ((event.metaKey && !event.ctrlKey) || ((event.ctrlKey || event.metaKey) && event.shiftKey))) {
            if (terminal.hasSelection()) {
                copySelection();
            }

            return false;
        }
        // Paste on ⌘V, Ctrl+V, or Ctrl/⌘+Shift+V.
        if (key === 'v' && (event.metaKey || event.ctrlKey)) {
            pasteClipboard();

            return false;
        }

        return true;
    });
    // Right-click pastes, like a traditional terminal.
    screen.value?.addEventListener('contextmenu', (event) => {
        event.preventDefault();
        pasteClipboard();
    });

    let after = 0;
    let pending = '';
    let sending = false;

    // Keystrokes go in order, a batch at a time.
    const flush = async () => {
        if (sending || pending === '' || !open) {
            return;
        }
        sending = true;
        const input = pending.slice(0, 4096);
        pending = pending.slice(input.length);
        try {
            await send('POST', `${base.value}/input`, { input });
        } catch {
            state.value = t('Closed');
        }
        sending = false;
        void flush();
    };
    terminal.onData((input) => {
        pending += input;
        void flush();
    });

    // Output is fetched as it arrives; each fetch says what was received last.
    const poll = async () => {
        if (!open) {
            return;
        }
        try {
            const { data: output } = await send<{ data: { status: string; reason: string | null; frames: Array<{ sequence: number; data: string }> } }>('GET', `${base.value}/output?after=${after}`);
            for (const frame of output.frames) {
                terminal.write(frame.data);
                after = frame.sequence;
            }
            if (output.status === 'connected') {
                state.value = t('Connected');
            }
            if (!['connecting', 'connected'].includes(output.status) && output.frames.length === 0) {
                open = false;
                state.value = `${t('Closed')} (${output.reason ?? output.status})`;
                terminal.options.disableStdin = true;
                return;
            }
            window.setTimeout(poll, output.frames.length > 0 ? 50 : 250);
        } catch {
            open = false;
            state.value = t('Closed');
        }
    };
    void poll();
    dispose = () => {
        open = false;
        terminal.dispose();
    };
});
onBeforeUnmount(() => dispose?.());
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader
            :overview="data.overview"
            :title="t('Terminal')"
            :description="t('A root shell on :server. It closes after :idle idle minutes, or :limit minutes at most. Opening and closing it are recorded in the audit log.', { server: data.server.label, idle: data.idleMinutes, limit: data.sessionMinutes })"
        />
        <AcmeAlert v-if="!data.ownBrowser" tone="warning">{{ t('This terminal was opened in another browser or tab session. Open a new one from the server page.') }}</AcmeAlert>
        <AcmeAlert v-else-if="!data.terminal.active" tone="info">{{ t('This terminal has closed (:reason).', { reason: data.terminal.closeReason ?? data.terminal.status }) }}</AcmeAlert>
        <section v-else class="ui-card grid gap-2 overflow-hidden p-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs text-muted" role="status" aria-live="polite">{{ state }}</p>
                <div v-if="clipboard" class="flex items-center gap-2">
                    <UiButton size="sm" variant="secondary" @click="clipboard.copy()">{{ t('Copy') }}</UiButton>
                    <UiButton size="sm" variant="secondary" @click="clipboard.paste()">{{ t('Paste') }}</UiButton>
                </div>
            </div>
            <div class="overflow-x-auto rounded-control bg-[#0b1020] p-2"><div ref="screen" /></div>
            <p class="hidden text-xs text-muted sm:block">{{ t('Select to copy with ⌘/Ctrl+Shift+C; paste with ⌘/Ctrl+V or right-click.') }}</p>
        </section>
        <div class="flex flex-wrap gap-3">
            <ApiForm v-if="data.terminal.active" :action="base" method="DELETE"><SubmitButton variant="secondary">{{ t('Close terminal') }}</SubmitButton></ApiForm>
            <AcmeBtn :to="`/projects/${project.id}/infrastructure/servers/${data.server.id}`" variant="ghost">{{ t('Back to the server') }}</AcmeBtn>
        </div>
    </div>
</template>
