<script setup lang="ts">
import type { ObjectiveSettings } from '~/types/monitoring';
import type { Option } from '~/types/ui';

/** Add or edit a service level objective; the fields for availability or latency appear for the chosen measure. */
const props = defineProps<{ objective: ObjectiveSettings | null; projectId: string; environments: Option[] }>();
const { t, tc } = useT();
const objective = computed(() => props.objective);
const environment = ref<string | null>(objective.value?.environmentId ?? props.environments[0]?.value ?? null);
const environmentOptions = computed(() => (objective.value ? props.environments.filter((option) => option.value === objective.value?.environmentId) : props.environments));
const indicator = ref<string | null>(objective.value?.indicator ?? 'availability');
const windowDays = ref<string | null>(String(objective.value?.windowDays ?? 30));
const indicators = computed(() => [{ value: 'availability', label: t('Availability (status codes)') }, { value: 'latency', label: t('Latency (response time)') }]);
const windows = computed(() => [7, 30].map((days) => ({ value: String(days), label: tc(':count day|:count days', days) })));
const action = computed(() => `/api/app/projects/${props.projectId}/monitoring/objectives${objective.value ? `/${objective.value.id}` : ''}`);
</script>

<template>
    <ApiForm :action="action" :method="objective ? 'PUT' : 'POST'" class="grid items-start gap-5 sm:grid-cols-2">
        <InputField name="name" :label="t('Name')" :model-value="objective?.name" placeholder="Checkout availability" maxlength="120" required autofocus />
        <SelectField v-model="environment" name="environment_id" :label="t('Environment')" :options="environmentOptions" :description="objective ? t('The environment can’t be changed.') : undefined" required />
        <SelectField v-model="indicator" name="indicator" :label="t('Measure')" :options="indicators" required />
        <InputField name="target" type="number" step="0.001" min="0.001" max="99.999" :label="t('Target (%)')" :model-value="String(objective?.target ?? 99.9)" required />
        <SelectField v-model="windowDays" name="window_days" :label="t('Rolling window')" :options="windows" required />
        <InputField v-if="indicator === 'latency'" name="latency_threshold_ms" type="number" step="0.001" min="1" :label="t('Fast enough under (ms)')" :model-value="String(objective?.latencyThresholdMs ?? 300)" required />
        <template v-else>
            <InputField name="status_min" type="number" min="100" max="599" :label="t('Lowest good status')" :model-value="String(objective?.statusMin ?? 200)" required />
            <InputField name="status_max" type="number" min="100" max="599" :label="t('Highest good status')" :model-value="String(objective?.statusMax ?? 399)" required />
        </template>
        <InputField name="service" :label="t('Only this service')" :model-value="objective?.service" :description="t('Optional. The exact service name.')" maxlength="100" />
        <InputField name="route" :label="t('Only this route')" :model-value="objective?.route" :description="t('Optional, such as /checkout.')" maxlength="255" />
        <div class="sm:col-span-2"><CheckboxField name="enabled" unchecked-value="0" :label="t('Objective is on')" :checked="objective?.enabled ?? true" /></div>
        <div class="flex justify-end sm:col-span-2"><SubmitButton>{{ objective ? t('Save objective') : t('Add objective') }}</SubmitButton></div>
    </ApiForm>
</template>
