<script setup lang="ts">
import type { EnvironmentPage } from '~/types/deploy';

/** Where an environment runs, who may deploy to it and when: protection, locks, a weekly window and freezes. */
const props = defineProps<{ page: EnvironmentPage; base: string }>();
const { t, tc, dateTime } = useT();
const environment = computed(() => props.page.environment);
const days = computed(() => [t('Mon'), t('Tue'), t('Wed'), t('Thu'), t('Fri'), t('Sat'), t('Sun')].map((label, index) => ({ value: index + 1, label })));
const regions = computed(() => new Set(props.page.placements.filter((placement) => placement.server !== null).map((placement) => `${placement.provider ?? '?'} · ${placement.region ?? '?'}`)).size);
</script>

<template>
    <div class="space-y-10">
        <SettingsSection
            id="regions"
            :title="t('Regions')"
            :description="t('Where this environment runs: each website it deploys to, and the provider region of its server. Add a website in another region, then connect this environment’s repository to it, to run in more than one.')"
        >
            <div class="grid gap-3 p-4 sm:p-6">
                <Alert v-if="regions > 1" tone="info">
                    {{ tc('Runs in :count regions. Keep each region’s database close to its servers, or requests pay for the distance.|Runs in :count regions. Keep each region’s database close to its servers, or requests pay for the distance.', regions, { count: regions }) }}
                </Alert>
                <ul class="divide-y divide-line text-sm">
                    <li v-for="(placement, index) in page.placements" :key="index" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span class="font-bold text-ink">{{ placement.name }}</span>
                        <span v-if="placement.server" class="text-muted">{{ placement.provider }} · <span class="font-mono">{{ placement.region ?? '—' }}</span> · {{ placement.server }}</span>
                        <span v-else class="text-muted">{{ t('No server') }}</span>
                    </li>
                    <li v-if="page.placements.length === 0" class="py-2 text-muted">{{ t('Not deployed to a website yet.') }}</li>
                </ul>
                <div v-if="page.canManage"><UiButton :to="`/projects/${page.overview.project.id}/infrastructure/websites/create`" size="sm">{{ t('Add a website in another region') }}</UiButton></div>
            </div>
        </SettingsSection>

        <SettingsSection id="controls" :title="t('Deployment controls')" :description="t('Lock deploys during an incident or freeze, or allow them only in a weekly window.')">
            <ApiForm :action="`${base}/controls`" method="PUT" class="p-4 sm:p-6">
                <fieldset :disabled="!page.canManage" class="grid items-start gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <CheckboxField
                            name="protected"
                            :label="t('Protected environment')"
                            :checked="environment.protected"
                            :description="t('Only owners, admins and members allowed to deploy protected environments (under Account → Members) can deploy here or change these settings.')"
                        />
                    </div>
                    <div class="sm:col-span-2">
                        <CheckboxField
                            name="require_variable_approval"
                            :label="t('Variable changes need a second person')"
                            :checked="environment.requireVariableApproval"
                            :description="t('Adding, changing or removing a variable waits until someone else who can configure this environment approves it.')"
                        />
                    </div>
                    <div class="sm:col-span-2"><CheckboxField name="locked" :label="t('Lock deploys')" :checked="environment.locked" /></div>
                    <InputField name="lock_reason" :label="t('Reason (shown to people who try)')" :model-value="environment.lockReason" maxlength="500" />
                    <div class="sm:col-span-2"><CheckboxField name="window" :label="t('Only deploy in a window')" :checked="environment.windowDays !== null" /></div>
                    <fieldset class="flex flex-wrap gap-x-4 sm:col-span-2">
                        <legend class="mb-1 text-sm font-bold text-ink">{{ t('Days') }}</legend>
                        <CheckboxField
                            v-for="day in days"
                            :id="`day-${day.value}`"
                            :key="day.value"
                            name="days[]"
                            error-key="days"
                            :value="String(day.value)"
                            :label="day.label"
                            :checked="environment.windowDays?.includes(day.value) ?? false"
                        />
                    </fieldset>
                    <InputField name="start" type="time" :label="t('From')" :model-value="environment.windowStart" />
                    <InputField name="end" type="time" :label="t('Until')" :model-value="environment.windowEnd" />
                    <InputField name="timezone" :label="t('Time zone')" :model-value="environment.timezone" maxlength="64" />
                    <div v-if="page.canManage" class="flex justify-end sm:col-span-2"><SubmitButton variant="secondary">{{ t('Save controls') }}</SubmitButton></div>
                </fieldset>
            </ApiForm>
        </SettingsSection>

        <SettingsSection id="freezes" :title="t('Freezes')" :description="t('Dates when this environment takes no deploys, such as a holiday or a launch. Scheduled and push deploys wait too.')">
            <div class="grid gap-3 p-4 sm:p-6">
                <div v-for="freeze in page.freezes" :key="freeze.id" class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <span>
                        <Badge v-if="freeze.now" tone="warning">{{ t('Frozen now') }}</Badge>
                        <span class="font-semibold">{{ dateTime(freeze.startsAt) }} – {{ dateTime(freeze.endsAt) }}</span>
                        <span v-if="freeze.reason" class="text-muted"> · {{ freeze.reason }}</span>
                    </span>
                    <ApiForm v-if="page.canManage" :action="`${base}/freezes/${freeze.id}`" method="DELETE">
                        <SubmitButton variant="quiet" size="sm">{{ freeze.now ? t('End now') : t('Remove') }}</SubmitButton>
                    </ApiForm>
                </div>
                <p v-if="page.freezes.length === 0" class="text-sm text-muted">{{ t('No freezes planned.') }}</p>
                <FormDialog v-if="page.canManage" id="add-freeze" :title="t('Add a freeze')" :action="`${base}/freezes`" :submit="t('Add freeze')">
                    <template #trigger="{ open }"><div><UiButton @click="open">{{ t('Add a freeze') }}</UiButton></div></template>
                    <div class="grid items-start gap-5 sm:grid-cols-2">
                        <InputField id="freeze-starts" name="starts_at" type="datetime-local" :label="t('From')" required />
                        <InputField id="freeze-ends" name="ends_at" type="datetime-local" :label="t('Until')" required />
                        <InputField id="freeze-timezone" name="timezone" :label="t('Time zone')" :model-value="environment.timezone" maxlength="64" required />
                        <InputField id="freeze-reason" name="reason" :label="t('Reason (shown to people who try)')" maxlength="255" />
                    </div>
                </FormDialog>
            </div>
        </SettingsSection>
    </div>
</template>
