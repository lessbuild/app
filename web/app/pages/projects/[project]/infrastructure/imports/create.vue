<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';
import type { Option } from '~/types/ui';

/**
 * Import a server you already run (the Acme theme's import page, step one): how to reach it, then we look around over
 * SSH and show what we found before anything changes.
 */
definePageMeta({ layout: 'app', service: 'infrastructure', tab: 'infrastructure/servers', panel: 'follow' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<{ overview: ProjectOverview; types: Option[]; ubuntuVersions: string[]; publicKey: string }>(() => `/projects/${route.params.project}/infrastructure/imports/create`);
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Import a server')" :description="t('We connect over SSH, look around without changing anything, and show what we found before you confirm.')" />
        <AcmeStepper :steps="[t('Connect'), t('Review and import')]" :current="0" :label="t('Import progress')" class="max-w-md" />
        <AcmeCard :title="t('Connect')">
            <ApiForm :action="`/api/app/projects/${data.overview.project.id}/infrastructure/imports`" class="grid gap-5">
                <ServerImportFields :types="data.types" :public-key="data.publicKey" :ubuntu-versions="data.ubuntuVersions" />
                <div class="flex flex-wrap gap-2">
                    <SubmitButton>{{ t('Inspect server') }}</SubmitButton>
                    <CancelButton :to="`/projects/${data.overview.project.id}/infrastructure/servers`" />
                </div>
            </ApiForm>
        </AcmeCard>
    </div>
</template>
