<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';

/** Maintenance windows: while one is on, no monitor in the account opens an incident or sends alerts. */
definePageMeta({ layout: 'app', service: 'monitoring', tab: 'monitoring/rules' });
type Window = { id: number; name: string; reason: string | null; startsAt: string; endsAt: string; state: 'past' | 'active' | 'upcoming' };
const { t, dateTime } = useT();
const route = useRoute();
const { data } = await useApi<{ overview: ProjectOverview; accountName: string; windows: Window[]; canManage: boolean }>(() => `/projects/${route.params.project}/monitoring/maintenance`);
const project = computed(() => data.value.overview.project);
const base = computed(() => `/api/app/projects/${project.value.id}/monitoring/maintenance`);
const states = computed(() => ({ active: { tone: 'warning' as const, label: t('In progress') }, upcoming: { tone: 'info' as const, label: t('Upcoming') }, past: { tone: 'neutral' as const, label: t('Finished') } }));
/** An ISO time as a datetime-local value in UTC. */
const local = (iso: string) => iso.slice(0, 16);
const nextHour = () => {
    const at = new Date();
    at.setUTCMinutes(0, 0, 0);
    at.setUTCHours(at.getUTCHours() + 1);
    return at.toISOString().slice(0, 16);
};
const inTwoHours = () => {
    const at = new Date();
    at.setUTCMinutes(0, 0, 0);
    at.setUTCHours(at.getUTCHours() + 2);
    return at.toISOString().slice(0, 16);
};
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader
            :overview="data.overview"
            :title="t('Maintenance')"
            :description="t('During a maintenance window no monitor in :account opens an incident or sends alerts. Checks keep running and are recorded.', { account: data.accountName })"
        >
            <template v-if="data.canManage" #actions>
                <AcmeBtn variant="primary" :to="{ query: { dialog: 'schedule-maintenance' } }" icon="plus">{{ t('Schedule maintenance') }}</AcmeBtn>
            </template>
        </ProjectHeader>
        <SectionNav section="alerts" :project-id="project.id" />

        <EmptyState v-if="data.windows.length === 0" icon="clock" :title="t('No maintenance scheduled')" :description="t('Schedule a window before planned work so expected failures don’t page anyone.')" />
        <section v-else class="ui-card overflow-hidden">
            <ul class="divide-y divide-line" :aria-label="t('Maintenance windows')">
                <li v-for="window in data.windows" :key="window.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 font-semibold text-ink">{{ window.name }} <AcmeBadge :tone="acmeTone(states[window.state].tone)">{{ states[window.state].label }}</AcmeBadge></p>
                        <p class="mt-0.5 text-xs text-muted">{{ dateTime(window.startsAt) }} – {{ dateTime(window.endsAt) }}<template v-if="window.reason"> · {{ window.reason }}</template></p>
                    </div>
                    <div v-if="data.canManage" class="flex gap-1">
                        <FormDialog v-if="window.state !== 'past'" :id="`edit-window-${window.id}`" :title="t('Edit')" :description="t('Times are in UTC.')" :action="`${base}/${window.id}`" method="PUT" :submit="t('Save')">
                            <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Edit') }}</AcmeBtn></template>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <InputField :id="`window-${window.id}-name`" name="name" :label="t('Name')" :model-value="window.name" maxlength="120" required />
                                <InputField :id="`window-${window.id}-reason`" name="reason" :label="t('Reason')" :model-value="window.reason" maxlength="1000" />
                                <InputField :id="`window-${window.id}-starts`" name="starts_at" type="datetime-local" :label="t('Starts (UTC)')" :model-value="local(window.startsAt)" required />
                                <InputField :id="`window-${window.id}-ends`" name="ends_at" type="datetime-local" :label="t('Ends (UTC)')" :model-value="local(window.endsAt)" required />
                            </div>
                        </FormDialog>
                        <DeleteDialog :id="`delete-window-${window.id}`" :title="t('Delete :window?', { window: window.name })" :description="t('Monitors alert normally again during this time.')" :action="`${base}/${window.id}`" :submit-label="t('Delete')">
                            <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Delete') }}</AcmeBtn></template>
                        </DeleteDialog>
                    </div>
                </li>
            </ul>
        </section>

        <FormDialog v-if="data.canManage" id="schedule-maintenance" :title="t('Schedule maintenance')" :description="t('Times are in UTC.')" :action="base" :submit="t('Schedule')">
            <div class="grid gap-4 sm:grid-cols-2">
                <InputField id="window-new-name" name="name" :label="t('Name')" maxlength="120" required autofocus />
                <InputField id="window-new-reason" name="reason" :label="t('Reason')" maxlength="1000" />
                <InputField id="window-new-starts" name="starts_at" type="datetime-local" :label="t('Starts (UTC)')" :model-value="nextHour()" required />
                <InputField id="window-new-ends" name="ends_at" type="datetime-local" :label="t('Ends (UTC)')" :model-value="inTwoHours()" required />
            </div>
        </FormDialog>
    </div>
</template>
