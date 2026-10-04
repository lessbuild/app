<script setup lang="ts">
import type { ServerPage } from '~/types/infrastructure';

/** The last lines of one of a server's logs (`?log=` picks it), and sending its logs to Monitoring. */
const props = defineProps<{ page: ServerPage; base: string }>();
const { t } = useT();
const log = computed(() => props.page.log);
const shipping = computed(() => props.page.logShipping);
const names: Record<string, string> = { apt: 'APT', caddy: 'Caddy', mysql: 'MySQL', php: 'PHP-FPM' };
const label = (type: string) => names[type] ?? (type === 'provisioning' ? t('Provisioning') : type);
const environment = ref<string | null>(shipping.value?.environmentId ?? props.page.logEnvironments[0]?.value ?? null);
const shippingStatuses = computed<Record<string, string>>(() => ({ active: t('Active'), installing: t('Installing'), failed: t('Failed'), removing: t('Removing') }));
let timer: number | undefined;

// While a log is being fetched, look again every few seconds.
watch(() => log.value.fetching, (fetching) => {
    window.clearInterval(timer);
    timer = fetching ? window.setInterval(() => refreshNuxtData(), 4000) : undefined;
}, { immediate: import.meta.client });
onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <div class="space-y-10">
        <SettingsSection :title="t('Logs')" :description="t('The last 200 lines of each log.')">
            <div class="grid gap-3 p-4 sm:p-6">
                <nav :aria-label="t('Logs')" class="flex flex-wrap gap-2">
                    <UiButton
                        v-for="type in log.types"
                        :key="type"
                        :to="{ query: { tab: 'logs', log: type } }"
                        :variant="log.type === type ? 'soft' : 'quiet'"
                        size="sm"
                        :aria-current="log.type === type ? 'page' : undefined"
                    >
                        {{ label(type) }}
                    </UiButton>
                </nav>
                <p v-if="log.refreshedAt" class="text-xs text-muted"><Rich :text="t('Fetched :time')"><template #time><RelativeTime :at="log.refreshedAt" /></template></Rich></p>
                <Alert v-if="log.error" tone="danger">{{ log.error }}</Alert>
                <CodeBlock v-if="log.text" class="max-h-96 overflow-auto whitespace-pre-wrap" :code="log.text" />
                <p v-else-if="log.fetching" class="text-sm text-muted" role="status">{{ t('Fetching… refresh in a few seconds.') }}</p>
                <ApiForm v-if="page.server.status === 'active'" :action="`${base}/logs/${log.type}`"><SubmitButton variant="secondary" size="sm">{{ t('Fetch the latest') }}</SubmitButton></ApiForm>
            </div>
        </SettingsSection>
        <SettingsSection
            id="log-shipping"
            :title="t('Send logs to Monitoring')"
            :description="t('A small agent on the server sends system warnings and errors, and its websites’ Laravel warnings and errors with their stack traces, to Monitoring, where you can search them beside traces and alert on them. Up to 600 lines a minute.')"
        >
            <div class="grid gap-3 p-4 sm:p-6">
                <template v-if="shipping">
                    <p class="flex flex-wrap items-center gap-2 text-sm">
                        <Badge :tone="shipping.status === 'active' ? 'success' : shipping.status === 'failed' ? 'danger' : 'neutral'">{{ shippingStatuses[shipping.status] ?? shipping.status }}</Badge>
                        <span class="text-muted">{{ t('To :project · :environment', { project: shipping.project, environment: shipping.environment }) }}</span>
                        <NuxtLink v-if="shipping.status === 'active'" :to="`/projects/${shipping.projectId}/monitoring/events?environment=${shipping.environmentId}&type=log`" class="font-bold text-primary hover:underline">{{ t('See the logs') }}</NuxtLink>
                    </p>
                    <Alert v-if="shipping.error" tone="danger">{{ shipping.error }}</Alert>
                </template>
                <template v-if="page.canManage && page.server.status === 'active'">
                    <p v-if="page.logEnvironments.length === 0" class="text-sm text-muted">{{ t('Turn on Monitoring for a project first.') }}</p>
                    <template v-else>
                        <ApiForm :action="`${base}/log-shipping`" method="PUT" class="flex flex-wrap items-end gap-3">
                            <SelectField v-model="environment" name="environment_id" :label="t('Send to')" :options="page.logEnvironments" />
                            <SubmitButton variant="secondary">{{ shipping ? t('Reinstall') : t('Start sending') }}</SubmitButton>
                        </ApiForm>
                        <ApiForm v-if="shipping && shipping.status !== 'removing'" :action="`${base}/log-shipping`" method="DELETE">
                            <SubmitButton variant="quiet" size="sm">{{ t('Stop sending') }}</SubmitButton>
                        </ApiForm>
                    </template>
                </template>
            </div>
        </SettingsSection>
    </div>
</template>
