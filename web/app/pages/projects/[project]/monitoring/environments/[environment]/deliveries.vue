<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';
import type { Option, Tone } from '~/types/ui';

/** Every batch of events an environment sent and whether it was processed; failed ones can be retried. */
definePageMeta({ layout: 'app', service: 'monitoring' });
type DeliveriesPage = {
    overview: ProjectOverview;
    environment: { id: string; name: string };
    receipts: Array<{ id: string; receivedAt: string; source: string; status: string; statusLabel: string; statusTone: Tone; error: string | null; accepted: number; duplicates: number; retryable: boolean }>;
    page: number;
    lastPage: number;
    status: string | null;
    statuses: Option[];
    canRetry: boolean;
};
const { t, number, dateTime } = useT();
const route = useRoute();
const text = (value: unknown) => (typeof value === 'string' && value !== '' ? value : undefined);
const { data } = await useApi<DeliveriesPage>(() => `/projects/${route.params.project}/monitoring/environments/${route.params.environment}/deliveries`, () => ({ status: text(route.query.status), page: text(route.query.page) }));
const project = computed(() => data.value.overview.project);
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t(':environment deliveries', { environment: data.environment.name })" :description="t('Every batch of events this environment sent, and whether it was processed.')">
            <template #actions><AcmeBtn :to="`/projects/${project.id}/monitoring/setup`" size="sm">{{ t('Setup') }}</AcmeBtn></template>
        </ProjectHeader>
        <nav :aria-label="t('Delivery status')" class="flex flex-wrap gap-2">
            <AcmeBtn :to="{ query: {} }" :variant="data.status === null ? 'primary' : 'ghost'" size="sm" :aria-current="data.status === null ? 'page' : undefined">{{ t('All') }}</AcmeBtn>
            <AcmeBtn v-for="option in data.statuses" :key="option.value" :to="{ query: { status: option.value } }" :variant="data.status === option.value ? 'primary' : 'ghost'" size="sm" :aria-current="data.status === option.value ? 'page' : undefined">
                {{ option.label }}
            </AcmeBtn>
        </nav>
        <DataTable :caption="t('Deliveries')">
            <template #head>
                <tr>
                    <th scope="col">{{ t('Received') }}</th><th scope="col">{{ t('Source') }}</th><th scope="col">{{ t('Status') }}</th>
                    <th scope="col" class="text-right">{{ t('Accepted') }}</th><th scope="col" class="text-right">{{ t('Duplicates') }}</th><th scope="col"><span class="sr-only">{{ t('Actions') }}</span></th>
                </tr>
            </template>
            <tr v-for="receipt in data.receipts" :key="receipt.id">
                <td class="whitespace-nowrap">{{ dateTime(receipt.receivedAt) }}</td>
                <td>{{ receipt.source }}</td>
                <td><AcmeBadge :tone="acmeTone(receipt.statusTone)">{{ receipt.statusLabel }}</AcmeBadge><p v-if="receipt.error" class="mt-1 text-xs text-muted">{{ receipt.error }}</p></td>
                <td class="text-right tabular-nums">{{ number(receipt.accepted) }}</td>
                <td class="text-right tabular-nums">{{ number(receipt.duplicates) }}</td>
                <td class="text-right">
                    <ApiForm v-if="data.canRetry && receipt.retryable" :action="`/api/app/projects/${project.id}/monitoring/ingest-deliveries/${receipt.id}/retry`"><SubmitButton variant="quiet" size="sm">{{ t('Retry') }}</SubmitButton></ApiForm>
                </td>
            </tr>
            <tr v-if="data.receipts.length === 0"><td colspan="6" class="py-10 text-center text-muted">{{ t('No deliveries yet.') }}</td></tr>
        </DataTable>
        <Pager :page="data.page" :last-page="data.lastPage" />
    </div>
</template>
