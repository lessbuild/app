<script setup lang="ts">
import type { DashboardForm } from '~/types/monitoring';

/** A dashboard's name, range and widgets: shared by the Add a dashboard dialog and the edit page. */
const props = defineProps<{ form: DashboardForm; prefix: string }>();
const { t } = useT();
const dashboard = computed(() => props.form.dashboard);
const range = ref<string | null>(dashboard.value?.range ?? '24h');
const selected = computed(() => dashboard.value?.widgets ?? props.form.widgets.map((widget) => widget.value));
const descriptions = computed<Record<string, string>>(() => ({
    telemetry: t('Event volume, request duration and error rate, with a trend.'),
    event_mix: t('How many requests, queries, jobs, exceptions, logs and metrics arrived.'),
    incidents: t('Incidents from monitors and alert rules that are still open.'),
    monitors: t('Current health of HTTP, DNS, TLS, TCP, heartbeat and queue monitors.'),
    objectives: t('Compliance and error budget left for each SLO.'),
    projects: t('Each project and when it last sent telemetry.'),
}));
</script>

<template>
    <div class="grid gap-5">
        <div class="grid items-start gap-5 sm:grid-cols-2">
            <InputField :id="`${prefix}-name`" name="name" :label="t('Name')" :model-value="dashboard?.name" maxlength="120" placeholder="Production" required />
            <SelectField :id="`${prefix}-range`" v-model="range" name="range" :label="t('Telemetry range')" :options="form.ranges" required />
            <div class="sm:col-span-2"><TextareaField :id="`${prefix}-description`" name="description" :label="t('Description')" :model-value="dashboard?.description" maxlength="1000" rows="2" /></div>
        </div>
        <fieldset class="grid gap-3 sm:grid-cols-2">
            <legend class="mb-2 text-sm font-bold text-ink">{{ t('Widgets') }} <span class="font-normal text-muted">{{ t('(shown in this order; pick at least one)') }}</span></legend>
            <ChoiceField
                v-for="widget in form.widgets"
                :id="`${prefix}-widget-${widget.value}`"
                :key="widget.value"
                name="widgets[]"
                error-key="widgets"
                :value="widget.value"
                :label="widget.label"
                :description="descriptions[widget.value]"
                :checked="selected.includes(widget.value)"
                card
            />
            <div class="sm:col-span-2"><FieldError name="widgets" /></div>
        </fieldset>
    </div>
</template>
