<script setup lang="ts">
import type { ServerPage } from '~/types/infrastructure';

/** The alerts watching a server's readings, and adding one. */
const props = defineProps<{ page: ServerPage; base: string }>();
const { t, tc, number } = useT();
const metrics = computed(() => Object.entries(props.page.alertMetrics).map(([value, label]) => ({ value, label })));
const operators = [{ value: 'gte', label: '≥' }, { value: 'lte', label: '≤' }];
const scopes = computed(() => [{ value: 'server', label: t('This server') }, { value: 'account', label: t('Every server') }]);
</script>

<template>
    <SettingsSection id="alerts" :title="t('Alerts')" :description="t('Owners and admins get an email and an inbox message when a reading stays past a threshold, and when it recovers.')">
        <div class="grid gap-4 p-4 sm:p-6">
            <p v-if="page.alertRules.length === 0" class="text-sm text-muted">{{ t('No alerts yet.') }}</p>
            <ul v-else class="divide-y divide-line">
                <li v-for="rule in page.alertRules" :key="rule.id" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                    <span>
                        <span class="font-bold text-ink">{{ rule.name }}</span>
                        <span class="text-muted">
                            {{ rule.metric }} {{ rule.operator === 'lte' ? '≤' : '≥' }} {{ number(rule.threshold) }} ·
                            {{ tc(':count reading|:count readings in a row', rule.readings, { count: rule.readings }) }} ·
                            {{ rule.everyServer ? t('all servers') : t('this server') }}
                        </span>
                    </span>
                    <span class="flex items-center gap-2">
                        <Badge v-if="rule.alerting" tone="danger">{{ t('Alerting') }}</Badge>
                        <ApiForm v-if="page.canManage" :action="`${base}/alerts/${rule.id}`" method="DELETE"><SubmitButton variant="quiet" size="sm">{{ t('Remove') }}</SubmitButton></ApiForm>
                    </span>
                </li>
            </ul>
            <FormDialog v-if="page.canManage" id="add-server-alert" :title="t('Add an alert')" :description="t('Get told when CPU, memory, disk or load stays above a threshold.')" :action="`${base}/alerts`" :submit="t('Add alert')" size="wide">
                <template #trigger="{ open }"><div><UiButton size="sm" @click="open"><Icon name="plus" class="h-4 w-4" />{{ t('Add an alert') }}</UiButton></div></template>
                <div class="grid items-end gap-3 sm:grid-cols-3">
                    <InputField name="name" :label="t('Name')" placeholder="Disk almost full" maxlength="120" required autofocus />
                    <SelectField name="metric" :label="t('Metric')" :options="metrics" />
                    <div class="flex gap-2">
                        <SelectField name="operator" :label="t('When')" :options="operators" />
                        <InputField name="threshold" type="number" step="0.01" :label="t('Threshold')" model-value="90" required />
                    </div>
                    <InputField name="consecutive_breaches" type="number" min="1" max="20" :label="t('Readings in a row')" model-value="3" required />
                    <InputField name="cooldown_minutes" type="number" min="5" max="1440" :label="t('Quiet for (minutes)')" model-value="60" required />
                    <SelectField name="scope" :label="t('Applies to')" :options="scopes" />
                </div>
            </FormDialog>
        </div>
    </SettingsSection>
</template>
