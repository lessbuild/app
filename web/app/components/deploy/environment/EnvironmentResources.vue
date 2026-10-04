<script setup lang="ts">
import type { EnvironmentPage } from '~/types/deploy';

/** An environment's databases, caches and storage, and the variables each puts into .env. */
const props = defineProps<{ page: EnvironmentPage; base: string }>();
const { t } = useT();
const types = computed(() => Object.entries(props.page.resourceTypes).map(([value, label]) => ({ value, label })));
</script>

<template>
    <SettingsSection id="resources" :title="t('Resources')" :description="t('Databases, caches and storage. Their variables go into .env; managed Redis and Valkey are set up on the server.')">
        <div class="grid gap-4 p-4 sm:p-6">
            <div v-for="resource in page.resources" :key="resource.id" class="flex flex-wrap items-center justify-between gap-3 text-sm">
                <span class="min-w-0">
                    <span class="font-bold">{{ resource.name }}</span>
                    <span class="text-xs text-muted"> · {{ resource.type }} · {{ resource.managed ? t('managed') : t('external') }}<template v-if="resource.variables.length > 0"> · <span class="font-mono">{{ resource.variables.join(', ') }}</span></template></span>
                </span>
                <ApiForm v-if="page.canManage" :action="`${base}/resources/${resource.id}`" method="DELETE" :confirm="t('Remove :name?', { name: resource.name })">
                    <SubmitButton variant="quiet" size="sm">{{ t('Remove') }}</SubmitButton>
                </ApiForm>
            </div>
            <p v-if="page.resources.length === 0" class="text-sm text-muted">{{ t('No resources yet.') }}</p>
            <FormDialog
                v-if="page.canManage"
                id="add-resource"
                :title="t('Add a resource')"
                :description="t('A database, cache or other service this environment uses.')"
                :action="`${base}/resources`"
                :submit="t('Save resource')"
            >
                <template #trigger="{ open }"><div><UiButton @click="open"><Icon name="plus" class="h-4 w-4" />{{ t('Add a resource') }}</UiButton></div></template>
                <div class="grid items-start gap-4 sm:grid-cols-2">
                    <InputField id="resource-name" name="name" :label="t('Name')" placeholder="cache" maxlength="60" required autofocus />
                    <SelectField id="resource-type" name="type" :label="t('Type')" :options="types" />
                    <div class="sm:col-span-2"><CheckboxField id="resource-managed" name="is_managed" :label="t('Managed (MySQL uses the website’s database; Redis and Valkey run on the server)')" /></div>
                    <div class="sm:col-span-2">
                        <TextareaField id="resource-variables" name="variables" :label="t('Variables for an external service')" rows="3" class="font-mono" :description="t('KEY=value lines, e.g. AWS_BUCKET=assets.')" />
                    </div>
                </div>
            </FormDialog>
        </div>
    </SettingsSection>
</template>
