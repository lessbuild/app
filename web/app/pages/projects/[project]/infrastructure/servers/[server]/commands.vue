<script setup lang="ts">
import type { CommandsPage } from '~/types/infrastructure';
import type { Tone } from '~/types/ui';

/** The commands run on a server as root: running one, the history (filtered, paged), and a command's output. */
definePageMeta({ layout: 'app', service: 'infrastructure' });
const { t, dateTime } = useT();
const route = useRoute();
const { data } = await useApi<CommandsPage>(
    () => `/projects/${route.params.project}/infrastructure/servers/${route.params.server}/commands`,
    () => ({ status: typeof route.query.status === 'string' ? route.query.status : undefined, output: typeof route.query.output === 'string' ? route.query.output : undefined, cursor: typeof route.query.cursor === 'string' ? route.query.cursor : undefined }),
);
const project = computed(() => data.value.overview.project);
const base = computed(() => `/api/app/projects/${project.value.id}/infrastructure/servers/${data.value.server.id}/commands`);
const statuses = computed<Record<string, { tone: Tone; label: string }>>(() => ({
    queued: { tone: 'info', label: t('Queued') },
    running: { tone: 'info', label: t('Running') },
    succeeded: { tone: 'success', label: t('Succeeded') },
    failed: { tone: 'danger', label: t('Failed') },
    canceled: { tone: 'neutral', label: t('Canceled') },
}));
const filter = ref<string | null>(data.value.status ?? '');
const filters = computed(() => data.value.statuses.map((status) => ({ value: status, label: statuses.value[status]?.label ?? status })));
watch(filter, (value) => navigateTo({ query: { status: value || undefined } }));
const page = (cursor: string | null) => ({ query: { ...route.query, cursor: cursor ?? undefined, output: undefined } });
const running = computed(() => data.value.executions.some((execution) => !execution.finished) || (data.value.selected !== null && ['queued', 'running'].includes(data.value.selected.status)));
let timer: number | undefined;

// While a command runs, the list keeps itself current.
watch(running, (following) => {
    window.clearInterval(timer);
    timer = following ? window.setInterval(() => refreshNuxtData(), 3000) : undefined;
}, { immediate: import.meta.client });
onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader
            :overview="data.overview"
            :title="t('Commands on :server', { server: data.server.label })"
            :description="t('Commands run as root, one at a time, and time out after :seconds seconds. Output is stored encrypted and kept :days days.', { seconds: data.timeoutSeconds, days: data.retentionDays })"
        >
            <template #actions>
                <AcmeBtn :to="`/projects/${project.id}/infrastructure/servers/${data.server.id}`" variant="ghost">{{ t('Back to :server', { server: data.server.label }) }}</AcmeBtn>
                <a v-if="data.canManage" :href="`${base}/export`" class="ui-btn ui-btn-secondary" download>{{ t('Export CSV') }}</a>
            </template>
        </ProjectHeader>

        <AcmeCard v-if="data.canManage" :title="t('Run a command')">
            <ApiForm :action="base" class="grid gap-4">
                <TextareaField name="command" :label="t('Command')" rows="3" maxlength="4096" class="font-mono" placeholder="systemctl status caddy --no-pager" required />
                <div><SubmitButton :disabled="!data.server.active">{{ t('Run as root') }}</SubmitButton></div>
            </ApiForm>
        </AcmeCard>

        <AcmeCard :padded="false"
            v-if="data.selected"
            :title="t('Command #:id', { id: data.selected.id })"
            :description="['queued', 'running'].includes(data.selected.status) ? t('It’s still running; this updates by itself.') : t('Exit code :code', { code: data.selected.exitCode ?? '—' })"
        >
            <div class="grid gap-3 px-5 pb-5 sm:px-6 sm:pb-6">
                <CodeBlock class="whitespace-pre-wrap" :code="`# ${data.selected.command}`" />
                <CodeBlock v-if="data.selected.output !== null" class="max-h-96 overflow-auto whitespace-pre-wrap" :code="data.selected.output" />
            </div>
        </AcmeCard>

        <div class="max-w-xs"><SelectField v-model="filter" name="status" :label="t('Status')" :placeholder="t('All')" :options="filters" /></div>

        <DataTable :caption="t('Command history')">
            <template #head>
                <tr><th scope="col">#</th><th scope="col">{{ t('Command') }}</th><th scope="col">{{ t('Status') }}</th><th scope="col">{{ t('Run') }}</th><th v-if="data.canManage" scope="col"><span class="sr-only">{{ t('Actions') }}</span></th></tr>
            </template>
            <tr v-for="execution in data.executions" :key="execution.id">
                <td>{{ execution.id }}</td>
                <td class="max-w-md">
                    <NuxtLink :to="{ query: { ...$route.query, output: String(execution.id) } }" class="break-all font-mono text-xs text-primary hover:underline">{{ execution.command.slice(0, 120) }}</NuxtLink>
                </td>
                <td>
                    <AcmeBadge :tone="acmeTone(statuses[execution.status]?.tone ?? 'neutral')">{{ statuses[execution.status]?.label ?? execution.status }}</AcmeBadge>
                    <span v-if="execution.exitCode !== null" class="ml-1 text-xs text-muted">{{ t('exit :code', { code: execution.exitCode }) }}</span>
                </td>
                <td class="whitespace-nowrap text-xs text-muted">{{ execution.createdAt ? dateTime(execution.createdAt) : '—' }}<template v-if="execution.user"> · {{ execution.user }}</template></td>
                <td v-if="data.canManage" class="whitespace-nowrap">
                    <div class="flex gap-1">
                        <ApiForm v-if="execution.status === 'queued'" :action="`${base}/${execution.id}/cancel`"><SubmitButton variant="quiet" size="sm">{{ t('Cancel') }}</SubmitButton></ApiForm>
                        <template v-else-if="execution.finished">
                            <ApiForm :action="`${base}/${execution.id}/rerun`"><SubmitButton variant="quiet" size="sm">{{ t('Run again') }}</SubmitButton></ApiForm>
                            <ApiForm :action="`${base}/${execution.id}`" method="DELETE"><SubmitButton variant="quiet" size="sm">{{ t('Delete') }}</SubmitButton></ApiForm>
                        </template>
                    </div>
                </td>
            </tr>
            <tr v-if="data.executions.length === 0"><td colspan="5" class="py-8 text-center text-muted">{{ t('No commands yet.') }}</td></tr>
        </DataTable>
        <nav v-if="data.previousCursor || data.nextCursor" class="flex justify-between" :aria-label="t('Pages')">
            <AcmeBtn :to="page(data.previousCursor)" :class="!data.previousCursor && 'pointer-events-none opacity-50'" :aria-disabled="!data.previousCursor || undefined">{{ t('Newer') }}</AcmeBtn>
            <AcmeBtn :to="page(data.nextCursor)" :class="!data.nextCursor && 'pointer-events-none opacity-50'" :aria-disabled="!data.nextCursor || undefined">{{ t('Older') }}</AcmeBtn>
        </nav>
    </div>
</template>
