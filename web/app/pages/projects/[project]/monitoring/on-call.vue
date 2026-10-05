<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';
import type { Option } from '~/types/ui';

/** On-call rotations: who's on call now and next, cover arranged ahead, and adding or editing a rotation. */
definePageMeta({ layout: 'app', service: 'monitoring' });
type Schedule = {
    id: number;
    name: string;
    rotation: string;
    handoffDay: number;
    handoffTime: string;
    timezone: string;
    startsOn: string;
    memberIds: string[];
    now: string | null;
    upcoming: Array<{ user: string | null; starts: string; ends: string }>;
    overrides: Array<{ id: number; user: string; starts: string; ends: string }>;
};
const { t, locale } = useT();
const route = useRoute();
const { data } = await useApi<{ overview: ProjectOverview; schedules: Schedule[]; members: Option[]; canManage: boolean }>(() => `/projects/${route.params.project}/monitoring/on-call`);
const project = computed(() => data.value.overview.project);
const base = computed(() => `/api/app/projects/${project.value.id}/monitoring/on-call`);
const weekday = (day: number) => new Intl.DateTimeFormat(locale.value, { weekday: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(2024, 0, day)));
/** A shift's time in the schedule's own time zone, such as "Mon 3 Feb, 09:00". */
const when = (iso: string, zone: string) => new Intl.DateTimeFormat(locale.value, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', timeZone: zone }).format(new Date(iso));
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader
            :overview="data.overview"
            :title="t('On-call')"
            :description="t('Rotations that decide who gets paged. Choose a schedule as an email destination’s recipient and alerts go to whoever is on call when they fire.')"
        >
            <template v-if="data.canManage" #actions>
                <AcmeBtn variant="primary" :to="{ query: { dialog: 'new-schedule' } }" icon="plus">{{ t('Add a schedule') }}</AcmeBtn>
            </template>
        </ProjectHeader>
        <SectionNav section="alerts" :project-id="project.id" />

        <EmptyState v-if="data.schedules.length === 0" icon="users" :title="t('No on-call schedules yet')" :description="t('Add a rotation, then choose it as the recipient of an email destination so alerts reach whoever is on call.')" />
        <section v-for="schedule in data.schedules" :key="schedule.id" class="grid gap-5 rounded-2xl border border-line bg-surface p-5 shadow-card sm:p-6" :aria-labelledby="`schedule-${schedule.id}`">
            <div>
                <h2 :id="`schedule-${schedule.id}`" class="font-semibold text-ink">{{ schedule.name }}</h2>
                <p class="text-sm text-muted">
                    {{ schedule.rotation === 'weekly' ? t('Weekly, handing over :day at :time', { day: weekday(schedule.handoffDay), time: schedule.handoffTime }) : t('Daily, handing over at :time', { time: schedule.handoffTime }) }}
                    ({{ schedule.timezone }})
                </p>
            </div>
            <div :class="['flex items-center gap-3 rounded-xl border p-4', schedule.now ? 'border-emerald-500/30 bg-emerald-500/[.06]' : 'border-amber-500/30 bg-amber-500/[.06]']">
                <AcmeAvatar v-if="schedule.now" :name="schedule.now" />
                <AcmeIcon v-else name="alert" class="text-amber-600" />
                <span><span class="block text-xs text-muted">{{ t('On call now') }}</span><b class="font-medium text-ink">{{ schedule.now ?? t('No one') }}</b></span>
            </div>
            <div class="grid gap-2">
                <h3 class="text-sm font-medium text-ink">{{ t('Next turns') }}</h3>
                <ol class="grid gap-1 text-sm">
                    <li v-for="(shift, index) in schedule.upcoming" :key="index" class="flex flex-wrap justify-between gap-2">
                        <span class="flex items-center gap-2 font-medium text-ink"><AcmeIcon name="clock" :size="14" class="text-muted" />{{ shift.user ?? t('No one') }}</span>
                        <span class="text-muted">{{ when(shift.starts, schedule.timezone) }} – {{ when(shift.ends, schedule.timezone) }}</span>
                    </li>
                </ol>
            </div>
            <div v-if="schedule.overrides.length > 0" class="grid gap-2">
                <h3 class="text-sm font-medium text-ink">{{ t('Cover') }}</h3>
                <div v-for="override in schedule.overrides" :key="override.id" class="flex flex-wrap items-center justify-between gap-2 text-sm">
                    <span class="rounded-xl border border-line px-3 py-2"><b class="font-medium text-ink">{{ override.user }}</b> <span class="text-muted">{{ when(override.starts, schedule.timezone) }} – {{ when(override.ends, schedule.timezone) }}</span></span>
                    <ApiForm v-if="data.canManage" :action="`${base}/overrides/${override.id}`" method="DELETE"><SubmitButton variant="quiet" size="sm">{{ t('Remove') }}</SubmitButton></ApiForm>
                </div>
            </div>
            <div v-if="data.canManage" class="flex flex-wrap gap-2">
                <FormDialog
                    :id="`cover-${schedule.id}`"
                    :title="t('Add cover')"
                    :description="t('Put someone on call for a while instead, such as while the person whose turn it is is away. Times are in :zone.', { zone: schedule.timezone })"
                    :action="`${base}/${schedule.id}/overrides`"
                    :submit="t('Add cover')"
                >
                    <template #trigger="{ open }"><AcmeBtn size="sm" @click="open">{{ t('Add cover') }}</AcmeBtn></template>
                    <div class="grid items-start gap-5 sm:grid-cols-2">
                        <div class="sm:col-span-2"><SelectField :id="`cover-${schedule.id}-user`" name="user_id" :label="t('Who covers')" :options="data.members" /></div>
                        <InputField :id="`cover-${schedule.id}-starts`" name="starts_at" type="datetime-local" :label="t('From')" required />
                        <InputField :id="`cover-${schedule.id}-ends`" name="ends_at" type="datetime-local" :label="t('Until')" required />
                    </div>
                </FormDialog>
                <FormDialog :id="`edit-schedule-${schedule.id}`" :title="t('Edit schedule')" :action="`${base}/${schedule.id}`" method="PUT" :submit="t('Save')" size="wide">
                    <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Edit') }}</AcmeBtn></template>
                    <OnCallFields :schedule="schedule" :members="data.members" :prefix="`edit-${schedule.id}`" />
                </FormDialog>
                <DeleteDialog :id="`delete-schedule-${schedule.id}`" :title="t('Delete :name?', { name: schedule.name })" :action="`${base}/${schedule.id}`">
                    <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Delete') }}</AcmeBtn></template>
                </DeleteDialog>
            </div>
        </section>

        <FormDialog v-if="data.canManage" id="new-schedule" :title="t('Add an on-call schedule')" :action="base" :submit="t('Add schedule')" size="wide">
            <OnCallFields :schedule="null" :members="data.members" prefix="new" />
        </FormDialog>
    </div>
</template>
