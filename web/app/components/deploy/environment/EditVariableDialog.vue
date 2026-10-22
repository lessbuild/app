<script setup lang="ts">
import type { EnvironmentPage } from '~/types/deploy';

/**
 * Editing one of an environment's variables: its value, what it's used for, whether it's secret and when to rotate
 * it. The key stays as it is (add a new variable to rename one). A secret's value isn't shown: leave it empty to
 * keep it. Each save is a new version. The `trigger` slot gets `open`.
 */
const props = defineProps<{ page: EnvironmentPage; base: string; variable: EnvironmentPage['variables'][number] }>();
const { t } = useT();
const scopes = computed(() => Object.entries(props.page.scopes).map(([value, label]) => ({ value, label })));
const scope = ref<string | null>(props.variable.scope);
</script>

<template>
    <FormDialog
        :id="`edit-variable-${variable.id}`"
        :title="t('Edit :key', { key: variable.key })"
        :description="page.environment.requireVariableApproval ? t('Saving asks someone else to approve the change. Each change is a new version.') : t('Each change is a new version; the next deploy uses it.')"
        :action="`${base}/variables/${variable.id}`"
        method="PUT"
        :submit="t('Save variable')"
    >
        <template #trigger="{ open }"><slot name="trigger" :open="open" /></template>
        <div class="grid items-start gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <InputField
                    :id="`edit-variable-${variable.id}-value`"
                    name="value"
                    :label="t('Value')"
                    :model-value="variable.secret ? '' : variable.value"
                    :placeholder="variable.secret ? t('Leave empty to keep the secret value') : undefined"
                    autocomplete="off"
                    class="font-mono"
                    autofocus
                />
            </div>
            <SelectField :id="`edit-variable-${variable.id}-scope`" v-model="scope" name="scope" :label="t('Used for')" :options="scopes" />
            <InputField :id="`edit-variable-${variable.id}-rotate`" name="rotation_due_at" type="date" :label="t('Rotate by (optional)')" :model-value="variable.rotationDueAt?.slice(0, 10) ?? ''" />
            <div class="sm:col-span-2">
                <CheckboxField :id="`edit-variable-${variable.id}-secret`" name="is_secret" :label="t('Secret (hide the value)')" :checked="variable.secret" :description="variable.secret ? t('To stop hiding it, enter the value again.') : undefined" />
            </div>
        </div>
    </FormDialog>
</template>
