<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';
import type { Option } from '~/types/ui';

/**
 * A project's settings (the Acme theme's project settings): its name and description, its environments (added empty or
 * copied from another), saving it as a template, and deleting it.
 */
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
const sources = computed(() => [{ value: '', label: t('Start empty') }, ...data.value.overview.environments.map((environment) => ({ value: environment.id, label: environment.name }))]);
const source = ref('');
const confirmName = ref('');
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Project settings')" :description="t('Project details, environments and templates.')" />
        <div class="space-y-6">
            <AcmeCard :title="t('Details')" :description="t('The name and description your teammates see.')">
                <ApiForm :action="base" method="PUT" class="sm:max-w-xl">
                    <InputField v-model="name" name="name" :label="t('Project name')" maxlength="100" required />
                    <TextareaField v-model="description" name="description" :label="t('Description')" rows="3" maxlength="500" />
                    <p class="text-xs text-muted">{{ t('Project ID: :id', { id: project.id }) }}</p>
                    <div><SubmitButton>{{ t('Save') }}</SubmitButton></div>
                </ApiForm>
            </AcmeCard>

            <AcmeCard id="environments" class="scroll-mt-6" :title="t('Environments')" :description="t('Production is created with the project and can’t be removed. Add staging, development or preview environments as you need them.')" :padded="false">
                <ul class="divide-y divide-line border-y border-line">
                    <li v-for="environment in data.overview.environments" :key="environment.id" class="flex items-center gap-3 px-5 py-3 sm:px-6">
                        <span class="flex-1 font-medium text-ink">{{ environment.name }}</span>
                        <AcmeBadge :tone="environment.kind === 'production' ? 'violet' : 'gray'">{{ environment.kindLabel }}</AcmeBadge>
                        <DeleteDialog
                            v-if="environment.kind !== 'production'"
                            :id="`remove-environment-${environment.id}`"
                            :title="t('Remove :environment?', { environment: environment.name })"
                            :action="`${base}/environments/${environment.id}`"
                            :warning="t('Services stop using this environment.')"
                            :submit-label="t('Remove')"
                        >
                            <template #trigger="{ open }"><AcmeBtn size="sm" variant="ghost" icon="trash" :label="t('Remove :environment', { environment: environment.name })" @click="open" /></template>
                        </DeleteDialog>
                        <span v-else class="w-8" aria-hidden="true" />
                    </li>
                </ul>
                <ApiForm v-if="data.overview.canManage" :action="source ? `${base}/environments/clone` : `${base}/environments`" class="!grid gap-3 p-5 sm:grid-cols-[1fr_10rem_12rem_auto] sm:items-end sm:p-6">
                    <InputField id="environment-name" name="name" :label="t('New environment')" :placeholder="t('Staging')" maxlength="60" required />
                    <SelectField id="environment-kind" name="kind" :label="t('Kind')" :options="data.kinds" required />
                    <SelectField id="environment-source" v-model="source" :name="source ? 'source_id' : 'from'" :label="t('Copy settings from')" :options="sources" />
                    <SubmitButton variant="secondary">{{ source ? t('Clone') : t('Add') }}</SubmitButton>
                    <CheckboxField v-if="source" id="environment-secrets" name="copy_secrets" class="sm:col-span-4" :label="t('Copy secret values too')" :description="t('Copies deploy settings, workers, recipes and variables; schedules come across switched off. Leave this unticked to set new secrets for it.')" />
                </ApiForm>
            </AcmeCard>

            <AcmeCard :title="t('Save as a template')" :description="t('Start new projects set up like this one: its services, other environments, how production builds, runs and releases, its uptime checks on its own domain and its Analytics goals. Variable names come along with empty values; secrets, domains and servers never do.')">
                <ApiForm :action="`${base}/template`" class="!grid gap-3 sm:grid-cols-[1fr_1.5fr_auto] sm:items-end">
                    <InputField id="template-name" v-model="templateName" name="name" :label="t('Template name')" maxlength="100" required />
                    <InputField id="template-description" name="description" :label="t('Description')" :placeholder="t('What projects made from it are for')" maxlength="500" />
                    <SubmitButton variant="secondary">{{ t('Save template') }}</SubmitButton>
                </ApiForm>
            </AcmeCard>

            <section v-if="data.overview.canManage" class="rounded-2xl border border-rose-500/30 bg-rose-500/[.03] p-5 sm:p-6" aria-labelledby="danger-title">
                <h2 id="danger-title" class="font-semibold text-rose-700 dark:text-rose-300">{{ t('Delete this project') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ t('Deletes its environments and service settings. Services remove their data for this project. This can’t be undone.') }}</p>
                <ApiForm :action="base" method="DELETE" class="mt-4 !flex flex-wrap items-end gap-3">
                    <InputField id="delete-confirm" v-model="confirmName" name="confirm_name" :label="t('Type :name to confirm', { name: project.name })" autocomplete="off" required class="w-full sm:w-72" />
                    <SubmitButton variant="danger" :disabled="confirmName !== project.name">{{ t('Delete project') }}</SubmitButton>
                </ApiForm>
            </section>
        </div>
    </div>
</template>
