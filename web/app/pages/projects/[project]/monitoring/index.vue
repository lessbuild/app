<script setup lang="ts">
import type { MonitorSummary } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';

/** Monitoring's front page: the project's monitors and their health, and the third-party services it follows. */
definePageMeta({ layout: 'app', service: 'monitoring' });
type ThirdParty = { id: number; name: string; url: string; summary: string | null; affected: string[]; checkedAt: string | null; error: string | null; tone: 'success' | 'warning' | 'danger' | 'neutral' | 'info'; label: string };
type MonitorsPage = { overview: ProjectOverview; monitors: MonitorSummary[]; thirdParty: ThirdParty[]; providers: Record<string, string>; canManage: boolean };
const { t } = useT();
const route = useRoute();
const { data } = await useApi<MonitorsPage>(() => `/projects/${route.params.project}/monitoring`);
const project = computed(() => data.value.overview.project);
const provider = ref<string | null>(Object.keys(data.value.providers)[0] ?? 'custom');
const providers = computed(() => [...Object.entries(data.value.providers).map(([value, label]) => ({ value, label })), { value: 'custom', label: t('Another status page…') }]);
let timer: number | undefined;

// The list keeps itself current, as checks run every few minutes.
onMounted(() => (timer = window.setInterval(() => refreshNuxtData(), 15000)));
onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Monitors')" :description="t('Uptime, DNS, TLS and TCP checks, plus heartbeats from your jobs and signals from your queues.')">
            <template v-if="data.canManage" #actions>
                <UiButton variant="primary" :to="`/projects/${project.id}/monitoring/monitors/create`"><Icon name="plus" class="h-4 w-4" />{{ t('Add a monitor') }}</UiButton>
            </template>
        </ProjectHeader>

        <EmptyState
            v-if="data.monitors.length === 0"
            icon="check-circle"
            :title="t('Add your first monitor')"
            :description="t('Check a URL every few minutes, watch a certificate or DNS record, or have a cron job report in. When something breaks, an incident opens and your alert destinations hear about it.')"
        >
            <template v-if="data.canManage" #action><UiButton variant="primary" :to="`/projects/${project.id}/monitoring/monitors/create`">{{ t('Add a monitor') }}</UiButton></template>
        </EmptyState>
        <section v-else class="ui-card overflow-hidden">
            <ul class="divide-y divide-line" :aria-label="t('Monitors')">
                <li v-for="monitor in data.monitors" :key="monitor.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                    <div class="min-w-0">
                        <NuxtLink :to="`/projects/${project.id}/monitoring/monitors/${monitor.id}`" class="font-extrabold text-ink hover:underline">{{ monitor.name }}</NuxtLink>
                        <p class="mt-0.5 break-all text-xs text-muted">{{ monitor.typeLabel }} · {{ monitor.environment }} · {{ monitor.target }}</p>
                    </div>
                    <div class="flex items-center gap-3 text-xs text-muted">
                        <span v-if="monitor.checkedAt"><Rich :text="t('Checked :time')"><template #time><RelativeTime :at="monitor.checkedAt" /></template></Rich></span>
                        <HealthBadge :health="monitor.health" :label="monitor.healthLabel" />
                    </div>
                </li>
            </ul>
        </section>

        <SettingsSection id="third-party" :title="t('Services you depend on')" :description="t('The public status of the services your apps rely on, checked every five minutes, beside your own monitors.')">
            <div class="grid gap-4 p-4 sm:p-6">
                <div v-for="service in data.thirdParty" :key="service.id" class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <div class="min-w-0">
                        <a :href="service.url" class="font-bold text-ink hover:underline" rel="noopener noreferrer" target="_blank">{{ service.name }}</a>
                        <p class="text-xs text-muted">
                            {{ service.summary ?? '' }}<template v-if="service.affected.length > 0"> · {{ t('Affected: :components', { components: service.affected.join(', ') }) }}</template>
                            <template v-if="service.checkedAt"> · <Rich :text="t('checked :time')"><template #time><RelativeTime :at="service.checkedAt" /></template></Rich></template>
                        </p>
                        <p v-if="service.error" class="text-xs text-danger">{{ service.error }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Badge :tone="service.tone">{{ service.label }}</Badge>
                        <ApiForm v-if="data.canManage" :action="`/api/app/projects/${project.id}/monitoring/third-party/${service.id}`" method="DELETE">
                            <SubmitButton variant="quiet" size="sm" :aria-label="t('Stop following :name', { name: service.name })">{{ t('Remove') }}</SubmitButton>
                        </ApiForm>
                    </div>
                </div>
                <p v-if="data.thirdParty.length === 0" class="text-sm text-muted">{{ t('Not following any services yet.') }}</p>
                <ApiForm v-if="data.canManage" :action="`/api/app/projects/${project.id}/monitoring/third-party`" class="grid gap-3 sm:grid-cols-3 sm:items-end">
                    <SelectField v-model="provider" name="provider" :label="t('Service')" :options="providers" />
                    <template v-if="provider === 'custom'">
                        <InputField name="name" :label="t('Name (another status page)')" maxlength="80" />
                        <InputField name="url" type="url" :label="t('Status page address')" maxlength="255" placeholder="https://status.example.com" :description="t('Any status page built on Atlassian Statuspage.')" />
                    </template>
                    <div class="sm:col-span-3"><SubmitButton variant="secondary">{{ t('Follow') }}</SubmitButton></div>
                </ApiForm>
            </div>
        </SettingsSection>
    </div>
</template>
