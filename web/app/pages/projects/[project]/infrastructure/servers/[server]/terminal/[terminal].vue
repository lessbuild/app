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
        <Alert v-if="!data.ownBrowser" tone="warning">{{ t('This terminal was opened in another browser or tab session. Open a new one from the server page.') }}</Alert>
        <Alert v-else-if="!data.terminal.active" tone="info">{{ t('This terminal has closed (:reason).', { reason: data.terminal.closeReason ?? data.terminal.status }) }}</Alert>
        <section v-else class="ui-card grid gap-2 overflow-hidden p-3">
            <p class="text-xs text-muted" role="status" aria-live="polite">{{ state }}</p>
            <div class="overflow-x-auto rounded-control bg-[#0b1020] p-2"><div ref="screen" /></div>
        </section>
        <div class="flex flex-wrap gap-3">
            <ApiForm v-if="data.terminal.active" :action="base" method="DELETE"><SubmitButton variant="secondary">{{ t('Close terminal') }}</SubmitButton></ApiForm>
            <UiButton :to="`/projects/${project.id}/infrastructure/servers/${data.server.id}`" variant="quiet">{{ t('Back to the server') }}</UiButton>
        </div>
    </div>
</template>
