<script setup lang="ts">
import type { AlertRuleForm } from '~/types/monitoring';
import type { Option } from '~/types/ui';

/**
 * Add or edit an alert rule: what it watches, the threshold and window, and how many checks open or recover an
 * incident. Fields only some metrics use appear when one of those metrics is chosen.
 */
const props = defineProps<{ form: AlertRuleForm; projectId: string; environments: Option[] }>();
const { t } = useT();
const route = useRoute();
const rule = computed(() => props.form.rule);
const queried = (key: string) => (typeof route.query[key] === 'string' ? (route.query[key] as string) : null);
const environment = ref<string | null>(rule.value?.environmentId ?? props.environments[0]?.value ?? null);
const environmentOptions = computed(() => (rule.value ? props.environments.filter((option) => option.value === rule.value?.environmentId) : props.environments));
const metric = ref<string | null>(rule.value?.metric ?? queried('metric') ?? 'request_error_rate');
const metricOptions = computed(() => props.form.metrics.map((option) => ({ ...option, label: option.disabled ? `${option.label} — ${t('Pro and above')}` : option.label })));
const needs = computed(() => props.form.metrics.find((option) => option.value === metric.value)?.needs ?? null);
const windowMinutes = ref<string | null>(String(rule.value?.windowMinutes ?? 5));
const objective = ref<string | null>(rule.value?.objectiveId ? String(rule.value.objectiveId) : null);
const series = ref<string | null>(rule.value?.seriesId ? String(rule.value.seriesId) : queried('series'));
const aggregation = ref<string | null>(rule.value?.aggregation ?? 'last');
const comparison = ref<string | null>(rule.value?.comparison ?? 'gte');
const aggregations = computed(() => [
    { value: 'last', label: t('Latest value') }, { value: 'mean', label: t('Mean') }, { value: 'min', label: t('Minimum') }, { value: 'max', label: t('Maximum') }, { value: 'rate', label: t('Counter rate') },
]);
const comparisons = computed(() => [{ value: 'gte', label: t('At or above') }, { value: 'lte', label: t('At or below') }]);
const action = computed(() => `/api/app/projects/${props.projectId}/monitoring/rules${rule.value ? `/${rule.value.id}` : ''}`);
</script>

<template>
    <ApiForm :action="action" :method="rule ? 'PUT' : 'POST'" class="grid gap-6">
        <input v-if="rule" type="hidden" name="version" :value="rule.version">
        <div class="grid items-start gap-5 sm:grid-cols-2">
            <InputField name="name" :label="t('Name')" :model-value="rule?.name" maxlength="120" required autofocus />
            <SelectField v-model="environment" name="environment_id" :label="t('Environment')" :options="environmentOptions" :description="rule ? t('The environment can’t be changed.') : undefined" required />
        </div>
        <SelectField v-model="metric" name="metric" :label="t('What to watch')" :options="metricOptions" required />

        <InputField v-if="needs === 'match'" name="match_text" :label="t('Text to match')" :model-value="rule?.matchText" :description="t('Log lines containing this text are counted.')" maxlength="120" placeholder="payment provider timeout" required />
        <SelectField v-if="needs === 'objective'" v-model="objective" name="service_level_objective_id" :label="t('Objective')" :placeholder="t('Choose an objective')" :options="form.objectives" required />
        <template v-if="needs === 'numeric' || needs === 'series'">
            <SelectField v-model="series" name="metric_series_id" :label="t('Metric')" :placeholder="t('Choose a metric')" :options="form.series" :description="form.series.length === 0 ? t('No metrics received yet. Send some with OpenTelemetry first.') : undefined" required />
            <div v-if="needs === 'numeric'" class="grid items-start gap-5 sm:grid-cols-3">
                <SelectField v-model="aggregation" name="aggregation" :label="t('Calculation')" :options="aggregations" />
                <SelectField v-model="comparison" name="comparison" :label="t('Alert when')" :options="comparisons" />
                <InputField name="freshness_seconds" type="number" min="30" max="3600" :label="t('Freshest sample (seconds)')" :model-value="String(rule?.freshnessSeconds ?? 120)" />
            </div>
        </template>

        <div class="grid items-start gap-5 sm:grid-cols-2">
            <InputField name="threshold" type="number" step="0.001" :label="t('Threshold')" :model-value="String(rule?.threshold ?? 5)" required />
            <SelectField v-model="windowMinutes" name="window_minutes" :label="t('Over the last')" :options="form.windows" required />
            <InputField name="service" :label="t('Only this service')" :model-value="rule?.service" :description="t('Optional. The exact service name.')" maxlength="100" />
            <InputField name="minimum_samples" type="number" min="1" max="1000000" :label="t('Fewest samples to judge')" :model-value="String(rule?.minimumSamples ?? 20)" required />
            <InputField name="trigger_checks" type="number" min="1" max="10" :label="t('Breaching checks in a row to open')" :model-value="String(rule?.triggerChecks ?? 2)" required />
            <InputField name="recovery_checks" type="number" min="1" max="10" :label="t('Healthy checks in a row to recover')" :model-value="String(rule?.recoveryChecks ?? 2)" required />
        </div>
        <CheckboxField name="enabled" unchecked-value="0" :label="t('Rule is on')" :checked="rule?.enabled ?? true" />
        <p class="text-xs text-muted">{{ t('Rules run every minute on data that is at least a minute old. Too few samples, stale data or gaps count as “no data”, never as recovered. Changing what a rule checks closes its open incident as “rule changed”.') }}</p>
        <div class="flex justify-end"><SubmitButton>{{ rule ? t('Save rule') : t('Add rule') }}</SubmitButton></div>
    </ApiForm>
</template>
