<script setup lang="ts">
/** A client's fields: name, report recipients, their projects, the markup on costs and the monthly report. */
const props = defineProps<{
    prefix: string;
    projects: Array<{ id: string; name: string }>;
    client?: { name: string; emails: string[]; projectIds: string[]; markupPercent: number; monthlyReport: boolean };
}>();
const { t } = useT();
const markup = ref(String(props.client?.markupPercent ?? 0));
</script>

<template>
    <InputField :id="`${prefix}-name`" name="name" :label="t('Client name')" :model-value="client?.name ?? ''" maxlength="120" required />
    <InputField :id="`${prefix}-emails`" name="emails" :label="t('Report recipients')" :model-value="client?.emails.join(', ') ?? ''" :description="t('Up to five email addresses, separated by commas.')" maxlength="1000" />
    <fieldset class="grid gap-2">
        <legend class="text-sm font-bold text-ink">{{ t('Their projects') }}</legend>
        <p v-if="projects.length === 0" class="text-sm text-muted">{{ t('No projects yet.') }}</p>
        <label v-for="project in projects" :key="project.id" class="inline-flex items-center gap-2 text-sm text-ink">
            <input :id="`${prefix}-project-${project.id}`" type="checkbox" class="ui-check" name="project_ids[]" :value="project.id" :checked="client?.projectIds.includes(project.id)">
            {{ project.name }}
        </label>
        <FieldError name="project_ids" />
    </fieldset>
    <InputField :id="`${prefix}-markup`" v-model="markup" name="markup_percent" type="number" min="0" max="500" :label="t('Markup on costs (%)')" />
    <CheckboxField :id="`${prefix}-monthly`" name="monthly_report" unchecked-value="0" :checked="client?.monthlyReport ?? true" :label="t('Email them a report on the 1st of each month')" />
</template>
