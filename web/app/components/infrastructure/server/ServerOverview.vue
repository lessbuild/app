<script setup lang="ts">
import type { ServerPage } from '~/types/infrastructure';

/** A server's resources over the last day, running a command, its history, and opening a terminal. */
const props = defineProps<{ page: ServerPage; base: string }>();
const { t, number, dateTime } = useT();
const server = computed(() => props.page.server);
const points = computed(() => props.page.cpu.map((reading) => ({ label: dateTime(reading.at), value: reading.value })));
const project = computed(() => props.page.overview.project.id);
</script>

<template>
    <section v-if="server.status === 'active'" class="ui-card grid gap-4 p-5" aria-labelledby="resources-heading">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="resources-heading" class="font-extrabold text-ink">{{ t('Resources') }}</h2>
            <div class="flex flex-wrap gap-2">
                <FormDialog
                    v-if="page.canRunCommands"
                    id="run-command"
                    :title="t('Run a command on :server', { server: server.label })"
                    :description="t('Runs as root over SSH. You’ll see its output on the commands page as soon as it finishes.')"
                    :action="`${base}/commands`"
                    :submit="t('Run as root')"
                >
                    <template #trigger="{ open }"><UiButton size="sm" @click="open"><Icon name="terminal" class="h-4 w-4" />{{ t('Run a command') }}</UiButton></template>
                    <TextareaField id="run-command-text" name="command" :label="t('Command')" rows="3" maxlength="4096" class="font-mono" placeholder="systemctl status caddy --no-pager" required autofocus />
                </FormDialog>
                <UiButton :to="`/projects/${project}/infrastructure/servers/${server.id}/commands`" variant="quiet" size="sm">{{ t('Command history') }}</UiButton>
                <ApiForm v-if="page.canOpenTerminal && server.hasHostKey" :action="`${base}/terminal`">
                    <input type="hidden" name="columns" value="120">
                    <input type="hidden" name="rows" value="32">
                    <SubmitButton variant="secondary" size="sm">{{ t('Open terminal') }}</SubmitButton>
                </ApiForm>
            </div>
        </div>
        <p v-if="page.latest === null" class="text-sm text-muted">{{ t('Metrics are collected every five minutes. The first reading appears shortly.') }}</p>
        <template v-else>
            <dl class="grid gap-3 sm:grid-cols-4">
                <div class="rounded-control bg-surface-muted p-3"><dt class="text-xs text-muted">{{ t('CPU') }}</dt><dd class="mt-1 text-xl font-extrabold text-ink">{{ page.latest.cpu }}%</dd></div>
                <div class="rounded-control bg-surface-muted p-3"><dt class="text-xs text-muted">{{ t('Memory') }}</dt><dd class="mt-1 text-xl font-extrabold text-ink">{{ page.latest.memory }}%</dd></div>
                <div class="rounded-control bg-surface-muted p-3"><dt class="text-xs text-muted">{{ t('Disk') }}</dt><dd class="mt-1 text-xl font-extrabold text-ink">{{ page.latest.disk }}%</dd></div>
                <div class="rounded-control bg-surface-muted p-3"><dt class="text-xs text-muted">{{ t('Load (1 min)') }}</dt><dd class="mt-1 text-xl font-extrabold text-ink">{{ number(page.latest.load) }}</dd></div>
            </dl>
            <BarChart :label="t('CPU use over the last 24 hours')" :points="points" unit="%" />
            <p class="text-xs text-muted">
                <Rich :text="t('Last reading :time · up :days days · :processes processes', { days: page.latest.uptimeDays, processes: page.latest.processes })">
                    <template #time><RelativeTime :at="page.latest.recordedAt" /></template>
                </Rich>
            </p>
        </template>
    </section>
    <section v-else class="ui-card p-5 text-sm text-muted">{{ t('Resources, alerts and diagnostics appear once the server is active.') }}</section>
</template>
