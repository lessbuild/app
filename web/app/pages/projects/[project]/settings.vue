<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';
import type { Option } from '~/types/ui';

/** A project's name and description, its environments, saving it as a template, and deleting it. */
definePageMeta({ layout: 'app' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<{ overview: ProjectOverview; kinds: Option[] }>(() => `/projects/${route.params.project}/settings`);
const project = computed(() => data.value.overview.project);
const base = computed(() => `/api/app/projects/${project.value.id}`);
const name = ref(project.value.name);
const description = ref(project.value.description ?? '');
const templateName = ref(project.value.name);
watch(project, (value) => {
    name.value = value.name;
    description.value = value.description ?? '';
});
const sources = computed(() => data.value.overview.environments.map((environment) => ({ value: environment.id, label: environment.name })));
</script>

<template>
    <div class="space-y-10">
        <ProjectHeader :overview="data.overview" :title="t('Project settings')" />

        <SettingsSection :title="t('Details')" :description="t('The name and description your teammates see.')">
            <ApiForm :action="base" method="PUT" class="p-4 sm:p-6">
                <InputField v-model="name" name="name" :label="t('Project name')" maxlength="100" required />
                <TextareaField v-model="description" name="description" :label="t('Description')" rows="3" maxlength="500" />
                <div class="flex justify-end"><SubmitButton>{{ t('Save') }}</SubmitButton></div>
            </ApiForm>
        </SettingsSection>

        <SettingsSection id="environments" :title="t('Environments')" :description="t('Production is created with the project and can’t be removed. Add staging, development or preview environments as you need them.')">
            <ul class="divide-y divide-line">
                <li v-for="environment in data.overview.environments" :key="environment.id" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6">
                    <span class="flex items-center gap-2">
                        <span class="font-bold text-ink">{{ environment.name }}</span>
                        <Badge :tone="environment.kind === 'production' ? 'accent' : 'neutral'">{{ environment.kindLabel }}</Badge>
                    </span>
                    <DeleteDialog
                        v-if="environment.kind !== 'production'"
                        :id="`remove-environment-${environment.id}`"
                        :title="t('Remove :environment?', { environment: environment.name })"
                        :action="`${base}/environments/${environment.id}`"
                        :warning="t('Services stop using this environment.')"
                        :submit-label="t('Remove')"
                    >
                        <template #trigger="{ open }"><UiButton variant="quiet" size="sm" @click="open">{{ t('Remove') }}</UiButton></template>
                    </DeleteDialog>
                </li>
            </ul>
            <template #footer>
                <div class="flex flex-wrap justify-end gap-2 px-4 py-3 sm:px-6">
                    <FormDialog id="clone-environment" :title="t('Clone')" :action="`${base}/environments/clone`" :submit="t('Clone')">
                        <template #trigger="{ open }"><UiButton size="sm" @click="open">{{ t('Clone') }}</UiButton></template>
                        <SelectField name="source_id" :label="t('Copy from')" :options="sources" required />
                        <InputField name="name" :label="t('Name')" :placeholder="t('Staging')" maxlength="60" required />
                        <SelectField name="kind" :label="t('Kind')" :options="data.kinds" required />
                        <CheckboxField name="copy_secrets" :label="t('Copy secret values too')" :description="t('Copies deploy settings, workers, recipes and variables; schedules come across switched off. Leave this unticked to set new secrets for it.')" />
                    </FormDialog>
                    <FormDialog id="add-environment" :title="t('New environment')" :action="`${base}/environments`" :submit="t('Add')">
                        <template #trigger="{ open }"><UiButton variant="primary" size="sm" @click="open"><Icon name="plus" class="h-4 w-4" />{{ t('New environment') }}</UiButton></template>
                        <InputField name="name" :label="t('Name')" :placeholder="t('Staging')" maxlength="60" required autofocus />
                        <SelectField name="kind" :label="t('Kind')" :options="data.kinds" required />
                    </FormDialog>
                </div>
            </template>
        </SettingsSection>

        <SettingsSection :title="t('Save as a template')" :description="t('Start new projects set up like this one: its services, other environments, how production builds, runs and releases, its uptime checks on its own domain and its Analytics goals. Variable names come along with empty values; secrets, domains and servers never do.')">
            <ApiForm :action="`${base}/template`" class="p-4 sm:p-6">
                <InputField v-model="templateName" name="name" :label="t('Template name')" maxlength="100" required />
                <TextareaField name="description" :label="t('What projects made from it are for')" rows="2" maxlength="500" />
                <div class="flex justify-end"><SubmitButton variant="secondary">{{ t('Save template') }}</SubmitButton></div>
            </ApiForm>
        </SettingsSection>

        <SettingsSection :title="t('Delete this project')" :description="t('Deletes its environments and service settings. Services remove their data for this project. This can’t be undone.')">
            <div class="flex flex-wrap items-center justify-between gap-3 p-4 sm:p-6">
                <p class="text-sm text-muted">{{ t('Project ID: :id', { id: project.id }) }}</p>
                <DeleteDialog id="delete-project" :title="t('Delete :name', { name: project.name })" :action="base" :warning="t('Deletes its environments and service settings. Services remove their data for this project. This can’t be undone.')">
                    <template #trigger="{ open }"><UiButton variant="danger" @click="open">{{ t('Delete this project') }}</UiButton></template>
                    <InputField name="confirm_name" :label="t('Type :name to confirm', { name: project.name })" autocomplete="off" required />
                </DeleteDialog>
            </div>
        </SettingsSection>
    </div>
</template>
