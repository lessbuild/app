<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';
import type { Tone } from '~/types/ui';

/** What applying a configuration review did: the local changes, then each environment's deploy as it runs. */
definePageMeta({ layout: 'app', service: 'deploy' });
const { t } = useT();
const route = useRoute();
type Operation = { id: number; environment_slug: string; kind: string; status: string; build_id: number | null; attempts: number; failure_code: string | null; retry_sequence: number };
type ApplicationPage = {
    overview: ProjectOverview;
    application: { id: string; reviewId: string; requester: string; appliedAt: string | null };
    receipt: { status: string; operations: Operation[] };
    canRetry: boolean;
};
const { data } = await useApi<ApplicationPage>(() => `/projects/${route.params.project}/deploy/configuration/applications/${route.params.application}`);
const project = computed(() => data.value.overview.project);
const application = computed(() => data.value.application);
const base = computed(() => `/api/app/projects/${project.value.id}/deploy/configuration/applications/${application.value.id}/operations`);
const statuses = computed<Record<string, { tone: Tone; label: string }>>(() => ({
    succeeded: { tone: 'success', label: t('Succeeded') },
    locally_applied: { tone: 'success', label: t('Applied') },
    remote_failed: { tone: 'danger', label: t('Deploy failed') },
    needs_attention: { tone: 'warning', label: t('Needs attention') },
    deploying: { tone: 'info', label: t('Deploying') },
    awaiting_dispatch: { tone: 'neutral', label: t('Waiting to start') },
    pending: { tone: 'neutral', label: t('Waiting') },
    blocked: { tone: 'warning', label: t('Blocked') },
    awaiting_approval: { tone: 'warning', label: t('Waiting for approval') },
    delivered: { tone: 'info', label: t('Deploying') },
    failed: { tone: 'danger', label: t('Failed') },
    canceled: { tone: 'neutral', label: t('Canceled') },
}));
const status = (value: string) => statuses.value[value] ?? { tone: 'info' as const, label: value };
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Configuration applied')" :description="t('Review #:review, applied by :name.', { review: application.reviewId, name: application.requester })" />
        <section class="ui-card flex flex-wrap items-center gap-3 p-5">
            <Badge :tone="status(data.receipt.status).tone">{{ status(data.receipt.status).label }}</Badge>
            <span class="text-sm text-muted">{{ t('Local changes are in place; deploys run separately and show here.') }}</span>
            <RelativeTime v-if="application.appliedAt" :at="application.appliedAt" class="ml-auto text-xs text-muted" />
        </section>
        <DataTable v-if="data.receipt.operations.length > 0" :caption="t('Deploys')">
            <template #head>
                <tr><th scope="col">{{ t('Environment') }}</th><th scope="col">{{ t('Status') }}</th><th scope="col">{{ t('Deploy') }}</th><th scope="col"><span class="sr-only">{{ t('Actions') }}</span></th></tr>
            </template>
            <tr v-for="operation in data.receipt.operations" :key="operation.id">
                <td class="font-mono text-xs">
                    {{ operation.environment_slug }}<span v-if="operation.retry_sequence > 0" class="text-muted"> · {{ t('retry :n', { n: operation.retry_sequence }) }}</span>
                </td>
                <td>
                    <Badge :tone="status(operation.status).tone">{{ status(operation.status).label }}</Badge>
                    <span v-if="operation.failure_code" class="ml-1 text-xs text-muted">({{ operation.failure_code.replaceAll('_', ' ') }})</span>
                </td>
                <td>
                    <NuxtLink v-if="operation.build_id" :to="`/projects/${project.id}/deploy/builds/${operation.build_id}`" class="font-bold text-primary hover:underline">#{{ operation.build_id }}</NuxtLink>
                    <template v-else>—</template>
                </td>
                <td class="text-right">
                    <div class="inline-flex gap-1">
                        <ApiForm v-if="data.canRetry && ['failed', 'canceled'].includes(operation.status)" :action="`${base}/${operation.id}/retry`"><SubmitButton variant="quiet" size="sm">{{ t('Retry') }}</SubmitButton></ApiForm>
                        <ApiForm v-if="['pending', 'blocked', 'awaiting_approval'].includes(operation.status)" :action="`${base}/${operation.id}/cancel`"><SubmitButton variant="quiet" size="sm">{{ t('Cancel') }}</SubmitButton></ApiForm>
                    </div>
                </td>
            </tr>
        </DataTable>
    </div>
</template>
