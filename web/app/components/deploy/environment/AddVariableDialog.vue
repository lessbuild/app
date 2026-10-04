<script setup lang="ts">
/**
 * Adding a variable to an environment, in a dialog over any page of the project (`?dialog=add-variable`). It loads the
 * environment's name and scopes when it opens unless the page passes them; `back` is the setup guide to return to
 * afterwards. The `trigger` slot gets `open`.
 */
type Environment = { environment: { name: string }; scopes: Record<string, string> };
const props = defineProps<{ projectId: string; environmentId: string | number; page?: Environment; back?: string | null }>();
const { t } = useT();
const { data, failed } = useDialogData<Environment>('add-variable', () => `/projects/${props.projectId}/deploy/environments/${props.environmentId}`, () => props.page);
const scopes = computed(() => Object.entries(data.value?.scopes ?? {}).map(([value, label]) => ({ value, label })));
</script>

<template>
    <FormDialog
        v-if="data"
        id="add-variable"
        :title="t('Add a variable to :environment', { environment: data.environment.name })"
        :description="t('Saving a key that exists makes a new version of it. Secrets are encrypted and never shown again.')"
        :action="`/api/app/projects/${projectId}/deploy/environments/${environmentId}/variables`"
        :submit="t('Save variable')"
    >
        <template #trigger="{ open }"><slot name="trigger" :open="open" /></template>
        <input v-if="back" type="hidden" name="_return" :value="back">
        <div class="grid items-start gap-4 sm:grid-cols-2">
            <InputField name="key" :label="t('Key')" placeholder="STRIPE_SECRET" maxlength="255" required autofocus class="font-mono" />
            <InputField name="value" :label="t('Value')" autocomplete="off" class="font-mono" />
            <SelectField name="scope" :label="t('Used for')" :options="scopes" />
            <InputField name="rotation_due_at" type="date" :label="t('Rotate by (optional)')" />
            <div class="sm:col-span-2"><CheckboxField name="is_secret" :label="t('Secret (hide the value)')" checked /></div>
        </div>
    </FormDialog>
    <UiDialog v-else id="add-variable" :title="t('Add a variable')">
        <template #trigger="{ open }"><slot name="trigger" :open="open" /></template>
        <DialogLoading :failed="failed" />
    </UiDialog>
</template>
