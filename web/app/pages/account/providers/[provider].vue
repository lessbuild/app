<script setup lang="ts">
import type { ProviderSummary, ProviderType } from '~/types/providers';

/** One provider: its connection (checked now or on a schedule), its settings, recent checks, and removing it. */
definePageMeta({ layout: 'app', area: 'account' });
const { t, tc, dateTime, number } = useT();
const route = useRoute();
type Check = { id: number; checkedAt: string | null; successful: boolean; error: string | null; automatic: boolean; durationMs: number };
type Detail = ProviderSummary & { description: string | null; baseUrl: string | null; monitoringEnabled: boolean; checkIntervalMinutes: number; failureThreshold: number };
const { data } = await useApi<{ account: { id: string; name: string }; provider: Detail; checks: Check[]; servers: string[]; types: ProviderType[]; intervals: number[]; thresholds: number[] }>(() => `/account/providers/${route.params.provider}`);
const provider = computed(() => data.value.provider);
const checking = ref(false);
const result = ref<{ successful: boolean; message: string } | null>(null);
const interval = ref(String(provider.value.checkIntervalMinutes));
const threshold = ref(String(provider.value.failureThreshold));
const intervals = computed(() => data.value.intervals.map((minutes) => ({ value: String(minutes), label: minutes < 60 * 24 ? tc(':count hour|:count hours', Math.floor(minutes / 60)) : t('Day') })));
const thresholds = computed(() => data.value.thresholds.map((count) => ({ value: String(count), label: tc(':count failed check|:count failed checks in a row', count) })));

/** Check the connection now and say what happened. */
async function check() {
    checking.value = true;
    result.value = await send<{ successful: boolean; message: string }>('POST', `/account/providers/${provider.value.id}/check`).catch(() => null);
    await refreshPage();
    checking.value = false;
}
</script>

<template>
    <div class="space-y-8">
        <PageHeader :eyebrow="data.account.name" :title="provider.name" :description="`${provider.typeLabel} · ${provider.purpose}`" :breadcrumbs="[{ label: t('Providers'), to: '/account/providers' }]" />

        <div class="ui-card flex flex-wrap items-center justify-between gap-4 p-5">
            <div class="flex flex-wrap items-center gap-3">
                <ProviderHealth :status="provider.status" />
                <span class="text-sm text-muted">{{ provider.checkedAt ? t('Checked :time', { time: dateTime(provider.checkedAt) }) : t('Never checked') }}</span>
            </div>
            <AcmeBtn :disabled="checking" :aria-busy="checking || undefined" @click="check">{{ checking ? t('Working…') : t('Check connection') }}</AcmeBtn>
        </div>
        <AcmeAlert v-if="result" :tone="result.successful ? 'success' : 'danger'" :role="result.successful ? 'status' : 'alert'">{{ result.message }}</AcmeAlert>

        <AcmeCard :padded="false" :title="t('Settings')" :description="t('A new token, or a new type, resets the connection status until the next check.')">
            <ApiForm :action="`/api/app/account/providers/${provider.id}`" method="PUT" class="p-4 sm:p-6">
                <div class="grid items-start gap-5 sm:grid-cols-2">
                    <ProviderFields :types="data.types" :provider="provider" />
                    <CheckboxField name="connection_monitoring_enabled" unchecked-value="0" :checked="provider.monitoringEnabled" :label="t('Check the connection automatically')" class="sm:col-span-2" />
                    <SelectField v-model="interval" name="connection_check_interval_minutes" :label="t('Check every')" :options="intervals" />
                    <SelectField v-model="threshold" name="connection_failure_threshold" :label="t('Mark failed after')" :options="thresholds" />
                </div>
                <div class="flex justify-end"><SubmitButton>{{ t('Save provider') }}</SubmitButton></div>
            </ApiForm>
        </AcmeCard>

        <DataTable :caption="t('Recent checks')">
            <template #head>
                <tr><th scope="col">{{ t('When') }}</th><th scope="col">{{ t('Result') }}</th><th scope="col">{{ t('How') }}</th><th scope="col">{{ t('Time taken') }}</th></tr>
            </template>
            <tr v-for="item in data.checks" :key="item.id">
                <td class="whitespace-nowrap">{{ item.checkedAt ? dateTime(item.checkedAt) : '—' }}</td>
                <td>{{ item.successful ? t('Connected') : (item.error ?? t('Failed')) }}</td>
                <td>{{ item.automatic ? t('Automatic') : t('Manual') }}</td>
                <td class="whitespace-nowrap">{{ number(item.durationMs) }} ms</td>
            </tr>
            <tr v-if="data.checks.length === 0"><td colspan="4" class="py-8 text-center text-muted">{{ t('No checks yet.') }}</td></tr>
        </DataTable>

        <AcmeCard :padded="false" :title="t('Remove this provider')" :description="data.servers.length === 0 ? t('The token stops being used at once.') : t('Delete its servers first: :servers.', { servers: data.servers.join(', ') })">
            <div class="p-4 sm:p-6">
                <DeleteDialog id="delete-provider" :title="t('Remove :provider?', { provider: provider.name })" :action="`/api/app/account/providers/${provider.id}`" :warning="t('The token stops being used at once.')" :submit-label="t('Remove provider')">
                    <template #trigger="{ open }"><AcmeBtn variant="danger" :disabled="data.servers.length > 0" @click="open">{{ t('Remove provider') }}</AcmeBtn></template>
                </DeleteDialog>
            </div>
        </AcmeCard>
    </div>
</template>
