<script setup lang="ts">
import type { AlertDestinationOptions } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';

/** One alert destination: testing it, its signing key, its settings and its deliveries. */
definePageMeta({ layout: 'app', service: 'monitoring', tab: 'monitoring/rules' });
type DestinationPage = {
    overview: ProjectOverview;
    destination: {
        id: number;
        name: string;
        type: string;
        typeLabel: string;
        target: string;
        enabled: boolean;
        version: number;
        archived: boolean;
        recipient: string | null;
        followsPerson: boolean;
        isPhone: boolean;
        usesUrl: boolean;
        isWebhook: boolean;
        isPagerDuty: boolean;
    };
    deliveries: Array<{ id: number; event: string; incidentId: number | null; status: string; errorCode: string | null; attempts: number; retryable: boolean; generation: number; createdAt: string | null }>;
    options: AlertDestinationOptions | null;
    canManage: boolean;
};
const { t, dateTime } = useT();
const route = useRoute();
const { data } = await useApi<DestinationPage>(() => `/projects/${route.params.project}/monitoring/alerts/${route.params.destination}`);
const project = computed(() => data.value.overview.project);
const destination = computed(() => data.value.destination);
const base = computed(() => `/api/app/projects/${project.value.id}/monitoring/alerts/${destination.value.id}`);
const secrets = useSecrets();
const events = computed<Record<string, string>>(() => ({ opened: t('Opened'), recovered: t('Recovered'), escalated: t('Escalated'), test: t('Test'), closed: t('Closed') }));
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="destination.name" :description="`${destination.typeLabel} · ${destination.target}`">
            <template v-if="data.canManage && !destination.archived" #actions>
                <ApiForm v-if="destination.enabled" :action="`${base}/test`">
                    <input type="hidden" name="version" :value="destination.version">
                    <SubmitButton variant="secondary" size="sm">{{ t('Send a test') }}</SubmitButton>
                </ApiForm>
                <ApiForm v-if="destination.isWebhook" :action="`${base}/rotate`">
                    <input type="hidden" name="version" :value="destination.version">
                    <SubmitButton variant="secondary" size="sm">{{ t('Replace signing key') }}</SubmitButton>
                </ApiForm>
            </template>
        </ProjectHeader>
        <SectionNav section="alerts" :project-id="project.id" />

        <AcmeAlert v-if="destination.archived" tone="info">{{ t('This destination is archived. It no longer receives alerts; its delivery history stays here.') }}</AcmeAlert>
        <AcmeAlert v-if="secrets?.signing_secret" tone="success" role="status">
            <p class="font-bold">{{ t('Copy the signing key now. It won’t be shown again.') }}</p>
            <CodeBlock :code="secrets.signing_secret" class="mt-2 whitespace-pre-wrap break-all" />
        </AcmeAlert>
        <section v-if="destination.isWebhook" class="ui-card grid gap-2 p-5 text-sm text-muted">
            <p>{{ t('Deliveries can arrive more than once: use the X-Beacon-Delivery header (or the JSON id) to skip duplicates.') }}</p>
            <p>{{ t('Check X-Beacon-Signature, which is v1= followed by HMAC-SHA256(key, timestamp + "." + raw body), and reject old X-Beacon-Timestamp values. Reply with any 2xx status; redirects aren’t followed.') }}</p>
        </section>

        <AcmeCard v-if="data.canManage && data.options && !destination.archived" :padded="false" :title="t('Settings')" :description="t('Changing the address or recipient cancels deliveries still waiting to be sent.')">
            <ApiForm :action="base" method="PUT" class="grid gap-5 px-5 pb-5 sm:px-6 sm:pb-6">
                <DestinationFields :options="data.options" :destination="destination" />
                <div class="flex justify-end"><SubmitButton>{{ t('Save') }}</SubmitButton></div>
            </ApiForm>
        </AcmeCard>

        <DataTable :caption="t('Deliveries')">
            <template #head>
                <tr><th scope="col">{{ t('Event') }}</th><th scope="col">{{ t('Outcome') }}</th><th scope="col">{{ t('Attempts') }}</th><th scope="col">{{ t('Queued') }}</th><th scope="col"><span class="sr-only">{{ t('Actions') }}</span></th></tr>
            </template>
            <tr v-for="delivery in data.deliveries" :key="delivery.id">
                <td>
                    {{ events[delivery.event] ?? delivery.event }}
                    <template v-if="delivery.incidentId"> · <NuxtLink :to="`/projects/${project.id}/monitoring/incidents/${delivery.incidentId}`" class="text-primary hover:underline">#{{ delivery.incidentId }}</NuxtLink></template>
                </td>
                <td>{{ delivery.status }}<span v-if="delivery.errorCode" class="text-xs text-muted"> ({{ delivery.errorCode }})</span></td>
                <td>{{ delivery.attempts }}</td>
                <td class="whitespace-nowrap">{{ delivery.createdAt ? dateTime(delivery.createdAt) : '—' }}</td>
                <td class="text-right">
                    <ApiForm v-if="data.canManage && delivery.retryable" :action="`/api/app/projects/${project.id}/monitoring/deliveries/${delivery.id}/retry`" class="flex items-center justify-end gap-2">
                        <input type="hidden" name="generation" :value="delivery.generation">
                        <CheckboxField :id="`retry-confirm-${delivery.id}`" name="confirm" :label="t('It may send a duplicate')" required />
                        <SubmitButton variant="quiet" size="sm">{{ t('Retry') }}</SubmitButton>
                    </ApiForm>
                </td>
            </tr>
            <tr v-if="data.deliveries.length === 0"><td colspan="5" class="py-8 text-center text-muted">{{ t('No deliveries yet. Send a test, or choose this destination on a monitor.') }}</td></tr>
        </DataTable>

        <AcmeCard v-if="data.canManage && !destination.archived" :padded="false" :title="t('Archive this destination')" :description="t('It stops receiving alerts and deliveries still waiting are cancelled. Its history is kept.')">
            <div class="p-4 sm:p-6">
                <DeleteDialog id="archive-destination" :title="t('Archive :destination?', { destination: destination.name })" :description="t('Monitors stop sending alerts here.')" :action="base" :submit-label="t('Archive')">
                    <template #trigger="{ open }"><AcmeBtn variant="danger" @click="open">{{ t('Archive :destination', { destination: destination.name }) }}</AcmeBtn></template>
                    <input type="hidden" name="version" :value="destination.version">
                </DeleteDialog>
            </div>
        </AcmeCard>
    </div>
</template>
