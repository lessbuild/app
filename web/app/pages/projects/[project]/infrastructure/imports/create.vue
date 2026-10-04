<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';
import type { Option } from '~/types/ui';

/** Import a server you already run: we look around over SSH first and show what we found before anything changes. */
definePageMeta({ layout: 'app', service: 'infrastructure' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<{ overview: ProjectOverview; types: Option[]; ubuntuVersions: string[] }>(() => `/projects/${route.params.project}/infrastructure/imports/create`);
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Import a server')" :description="t('We connect over SSH, look around without changing anything, and show what we found before you confirm.')" />
        <section class="ui-card">
            <ApiForm :action="`/api/app/projects/${data.overview.project.id}/infrastructure/imports`" class="grid gap-5 p-4 sm:p-6">
                <ServerImportFields :types="data.types" :ubuntu-versions="data.ubuntuVersions" />
                <div class="flex flex-wrap gap-3">
                    <SubmitButton>{{ t('Inspect server') }}</SubmitButton>
                    <UiButton :to="`/projects/${data.overview.project.id}/infrastructure/servers`" variant="quiet">{{ t('Cancel') }}</UiButton>
                </div>
            </ApiForm>
        </section>
    </div>
</template>
