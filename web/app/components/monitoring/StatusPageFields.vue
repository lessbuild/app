<script setup lang="ts">
import type { StatusPageForm } from '~/types/monitoring';

/** A status page's fields and the monitors it shows (each with an optional group): shared by the dialog and edit page. */
const props = defineProps<{ form: StatusPageForm; prefix: string }>();
const { t } = useT();
const page = computed(() => props.form.page);
const chosen = (id: number) => page.value !== null && String(id) in page.value.components;
</script>

<template>
    <div class="grid gap-6">
        <div class="grid items-start gap-5 sm:grid-cols-2">
            <InputField :id="`${prefix}-name`" name="name" :label="t('Name')" :model-value="page?.name" maxlength="120" required />
            <InputField
                :id="`${prefix}-slug`"
                name="slug"
                :label="t('Public address')"
                :model-value="page?.slug"
                maxlength="100"
                placeholder="acme"
                :description="t('Shown as :url/status/…. Leave empty to make one from the name.', { url: form.baseUrl })"
            />
            <div class="sm:col-span-2">
                <TextareaField :id="`${prefix}-description`" name="description" :label="t('Description')" :model-value="page?.description" maxlength="1000" rows="3" :description="t('Optional. Shown under the page title.')" />
            </div>
            <div class="grid gap-2 sm:col-span-2">
                <CheckboxField
                    :id="`${prefix}-published`"
                    name="published"
                    unchecked-value="0"
                    :label="t('Published')"
                    :description="t('Anyone with the address sees component names, their state and your updates. Never monitor addresses or check details.')"
                    :checked="page?.published ?? false"
                />
                <CheckboxField
                    :id="`${prefix}-monthly`"
                    name="monthly_report"
                    unchecked-value="0"
                    :label="t('Monthly uptime report')"
                    :description="t('On the 1st, email subscribers last month’s uptime for each component.')"
                    :checked="page?.monthlyReport ?? false"
                />
            </div>
        </div>
        <fieldset class="grid gap-3">
            <legend class="text-sm font-bold text-ink">{{ t('Components') }}</legend>
            <p class="text-xs text-muted">{{ t('Monitors shown on the page, in this order. Up to 25. Archived monitors aren’t shown.') }}</p>
            <p v-if="form.monitors.length === 0" class="text-sm text-muted">{{ t('Add a monitor first. Pages show monitors, not telemetry.') }}</p>
            <div v-for="monitor in form.monitors" :key="monitor.id" class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_12rem] sm:items-center">
                <ChoiceField
                    :id="`${prefix}-component-${monitor.id}`"
                    name="monitor_ids[]"
                    error-key="monitor_ids"
                    :value="String(monitor.id)"
                    :label="monitor.name"
                    :description="monitor.description"
                    :checked="chosen(monitor.id)"
                    card
                />
                <InputField
                    :id="`${prefix}-group-${monitor.id}`"
                    :name="`component_groups[${monitor.id}]`"
                    :error-key="`component_groups.${monitor.id}`"
                    :label="t('Group (optional)')"
                    :model-value="page?.components[String(monitor.id)] ?? ''"
                    maxlength="80"
                    placeholder="API"
                />
            </div>
            <FieldError name="monitor_ids" />
        </fieldset>
    </div>
</template>
