<script setup lang="ts">
import type { AlertRuleSummary } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';

/** One alert rule: its state and last value, its incidents, and (for admins) where its alerts go and how they escalate. */
definePageMeta({ layout: 'app', service: 'monitoring' });
type RulePage = {
    overview: ProjectOverview;
    rule: AlertRuleSummary & { version: number; archived: boolean; value: number | null; samples: number | null; checkedAt: string | null };
    incidents: Array<{ id: number; title: string; statusLabel: string; openedAt: string }>;
    destinations: Array<{ id: number; name: string; type: string }>;
    routes: number[];
    notifyOpened: boolean;
    notifyRecovered: boolean;
    escalations: Array<{ destinationId: number; delayMinutes: number }>;
    escalationSteps: number;
    canManage: boolean;
};
const { t, number } = useT();
const route = useRoute();
const { data } = await useApi<RulePage>(() => `/projects/${route.params.project}/monitoring/rules/${route.params.rule}`);
const project = computed(() => data.value.overview.project);
const rule = computed(() => data.value.rule);
const base = computed(() => `/api/app/projects/${project.value.id}/monitoring/rules/${rule.value.id}`);
const tones: Record<string, 'danger' | 'success' | 'neutral' | 'warning'> = { breaching: 'danger', healthy: 'success', no_data: 'warning' };
const destinationOptions = computed(() => data.value.destinations.map((destination) => ({ value: String(destination.id), label: destination.name })));
/** How many escalation rows to show: the saved steps plus one empty row, up to the plan's limit. */
const stepRows = computed(() => Math.min(data.value.escalationSteps, Math.max(3, data.value.escalations.length + 1)));
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="rule.name" :description="`${rule.condition} · ${rule.environment}`">
            <template v-if="data.canManage && !rule.archived" #actions>
                <UiButton :to="`/projects/${project.id}/monitoring/rules/${rule.id}/edit`" size="sm">{{ t('Edit') }}</UiButton>
                <DeleteDialog
                    id="archive-rule"
                    :title="t('Archive :rule?', { rule: rule.name })"
                    :description="t('It stops checking and an open incident closes as “rule archived”. Its history is kept.')"
                    :action="base"
                    :submit-label="t('Archive rule')"
                >
                    <template #trigger="{ open }"><UiButton variant="quiet" size="sm" @click="open">{{ t('Archive') }}</UiButton></template>
                    <input type="hidden" name="version" :value="rule.version">
                </DeleteDialog>
            </template>
        </ProjectHeader>
        <SectionNav section="alerts" :project-id="project.id" />

        <div class="flex flex-wrap items-center gap-3 text-sm text-muted">
            <Badge :tone="tones[rule.state] ?? 'neutral'">{{ rule.stateLabel }}</Badge>
            <span v-if="rule.value !== null || rule.samples !== null">{{ t('Last value: :value from :samples samples', { value: rule.value === null ? '—' : number(rule.value), samples: number(rule.samples ?? 0) }) }}</span>
            <span v-if="rule.checkedAt">· <Rich :text="t('Checked :time')"><template #time><RelativeTime :at="rule.checkedAt" /></template></Rich></span>
        </div>
        <Alert v-if="rule.archived" tone="info">{{ t('This rule is archived. It no longer runs; its history stays here.') }}</Alert>

        <section v-if="data.incidents.length > 0" class="ui-card overflow-hidden" aria-labelledby="rule-incidents">
            <h2 id="rule-incidents" class="border-b border-line px-5 py-3 text-sm font-bold text-ink">{{ t('Incidents') }}</h2>
            <ul class="divide-y divide-line">
                <li v-for="incident in data.incidents" :key="incident.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                    <NuxtLink :to="`/projects/${project.id}/monitoring/incidents/${incident.id}`" class="font-bold text-ink hover:underline">{{ incident.title }}</NuxtLink>
                    <span class="text-xs text-muted"><RelativeTime :at="incident.openedAt" /> · {{ incident.statusLabel }}</span>
                </li>
            </ul>
        </section>

        <template v-if="data.canManage && !rule.archived">
            <SettingsSection :title="t('Where alerts go')" :description="t('Destinations told when an incident opens or recovers.')">
                <ApiForm :action="`${base}/routing`" method="PUT" class="grid gap-3 p-4 sm:p-6">
                    <input type="hidden" name="version" :value="rule.version">
                    <p v-if="data.destinations.length === 0" class="text-sm text-muted">
                        {{ t('No alert destinations yet.') }}
                        <NuxtLink :to="`/projects/${project.id}/monitoring/alerts`" class="font-bold text-primary hover:underline">{{ t('Add a destination') }}</NuxtLink>
                    </p>
                    <CheckboxField
                        v-for="destination in data.destinations"
                        :id="`route-${destination.id}`"
                        :key="destination.id"
                        name="destinations[]"
                        error-key="destinations"
                        :value="String(destination.id)"
                        :checked="data.routes.includes(destination.id)"
                        :label="destination.name"
                        :description="destination.type"
                    />
                    <div class="mt-2 grid gap-2 border-t border-line pt-4">
                        <CheckboxField name="opened" unchecked-value="0" :label="t('When an incident opens')" :checked="data.notifyOpened" />
                        <CheckboxField name="recovered" unchecked-value="0" :label="t('When it recovers')" :checked="data.notifyRecovered" />
                    </div>
                    <div><SubmitButton variant="secondary" size="sm">{{ t('Save routing') }}</SubmitButton></div>
                </ApiForm>
            </SettingsSection>

            <SettingsSection :title="t('Escalation')" :description="t('If the incident is still open after each delay, alert one more destination. Delays must increase.')">
                <p v-if="data.escalationSteps === 0" class="p-4 text-sm text-muted sm:p-6">{{ t('Escalation steps come with Monitoring Pro and above.') }}</p>
                <ApiForm v-else :action="`${base}/escalations`" method="PUT" class="grid gap-4 p-4 sm:p-6">
                    <input type="hidden" name="version" :value="rule.version">
                    <FieldError name="escalations" />
                    <div v-for="index in stepRows" :key="index" class="grid items-start gap-3 sm:grid-cols-2">
                        <SelectField
                            :id="`escalation-${index - 1}-destination`"
                            :name="`escalations[${index - 1}][destination_id]`"
                            :error-key="`escalations.${index - 1}.destination_id`"
                            :label="t('Step :step', { step: index })"
                            :placeholder="t('No step')"
                            :options="destinationOptions"
                            :model-value="data.escalations[index - 1] ? String(data.escalations[index - 1]!.destinationId) : ''"
                        />
                        <InputField
                            :id="`escalation-${index - 1}-delay`"
                            :name="`escalations[${index - 1}][delay_minutes]`"
                            :error-key="`escalations.${index - 1}.delay_minutes`"
                            :label="t('After (minutes)')"
                            type="number"
                            min="1"
                            max="10080"
                            :model-value="data.escalations[index - 1] ? String(data.escalations[index - 1]!.delayMinutes) : String(index * 15)"
                        />
                    </div>
                    <p class="text-xs text-muted">{{ t('Minutes after the incident opens. Leave a step empty to skip it.') }}</p>
                    <div><SubmitButton variant="secondary" size="sm">{{ t('Save escalation') }}</SubmitButton></div>
                </ApiForm>
            </SettingsSection>
        </template>
    </div>
</template>
