<script setup lang="ts">
import type { WebsitePage } from '~/types/infrastructure';

/**
 * One website (the Acme theme's website page): setup followed live, then tabs for the overview, domains (with the CDN
 * and firewall), database, backups, files and settings. Visiting it and its deploys sit in the header.
 */
definePageMeta({ layout: 'app', service: 'infrastructure' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<WebsitePage>(() => `/projects/${route.params.project}/infrastructure/websites/${route.params.website}`);
const website = computed(() => data.value.website);
const base = computed(() => `/api/app/projects/${data.value.overview.project.id}/infrastructure/websites/${website.value.id}`);
const secrets = useSecrets();
const tabs = computed<Record<string, string>>(() => ({
    overview: t('Overview'),
    domains: t('Domains'),
    database: t('Database'),
    backups: t('Backups'),
    ...(data.value.canBrowseFiles ? { files: t('Files') } : {}),
    ...(data.value.canManage ? { settings: t('Settings') } : {}),
}));
const project = computed(() => data.value.overview.project.id);
const tab = computed(() => (typeof route.query.tab === 'string' && route.query.tab in tabs.value ? route.query.tab : 'overview'));
let timer: number | undefined;

// While it's being set up, the page keeps itself current.
watch(() => website.value.provisioning, (following) => {
    window.clearInterval(timer);
    timer = following ? window.setInterval(() => refreshNuxtData(), 5000) : undefined;
}, { immediate: import.meta.client });
onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="website.url" :description="[website.server, website.directory, `PHP ${website.phpVersion}`].filter(Boolean).join(' · ')">
            <template #actions>
                <AcmeBtn icon="external" :to="`https://${website.url}`" target="_blank" rel="noopener">{{ t('Visit') }}</AcmeBtn>
                <AcmeBtn v-if="data.repository" variant="primary" icon="rocket" :to="`/projects/${project}/deploy/repositories/${data.repository}`">{{ t('Deploys') }}</AcmeBtn>
            </template>
        </ProjectHeader>
        <div class="space-y-6">
            <OneTimeSecrets
                :labels="{ database: t('Database password') }"
                :title="t('Database password')"
                :description="t('Database and user :name on localhost. The password is shown once; it’s also in the server’s MySQL.', { name: secrets?.database_name ?? website.database })"
            />
            <OneTimeSecrets
                :labels="{ database_user: t('Password') }"
                :title="t('Password for :user', { user: secrets?.database_user_name ?? '' })"
                :description="t('It’s shown once. Connect to :database on localhost (through an SSH tunnel from elsewhere).', { database: website.database })"
            />

            <section v-if="website.provisioning" class="rounded-2xl border border-sky-500/30 bg-sky-500/[.05] p-5" aria-busy="true">
                <p class="flex flex-wrap items-center gap-2 font-semibold text-ink" role="status" aria-live="polite">
                    <span class="size-2 animate-pulse rounded-full bg-sky-500" aria-hidden="true" />
                    <WebsiteStatusBadge :status="website.status" />{{ t('Stage :stage of :final', { stage: website.stage, final: website.finalStage }) }}
                </p>
                <AcmeProgress :value="website.finalStage > 0 ? (website.stage / website.finalStage) * 100 : 0" :label="t('Setup progress')" class="mt-4" />
            </section>
            <section v-if="website.status === 'failed' || website.cleanupError" class="space-y-3 rounded-2xl border border-rose-500/30 bg-rose-500/[.04] p-5">
                <p v-if="website.status === 'failed'" class="flex items-center gap-2 font-semibold text-ink"><WebsiteStatusBadge :status="website.status" />{{ website.error }}</p>
                <p v-if="website.cleanupError" class="text-sm text-ink">{{ t('The copy on the previous server couldn’t be removed: :error', { error: website.cleanupError }) }}</p>
                <ApiForm v-if="data.canManage" :action="`${base}/retry`"><SubmitButton variant="secondary" size="sm">{{ t('Retry') }}</SubmitButton></ApiForm>
            </section>

            <PageTabs :tabs="tabs" :current="tab" :label="t('Website sections')" :counts="{ domains: data.domains.length }" />

            <WebsiteOverview v-if="tab === 'overview'" :page="data" />
            <WebsiteDomains v-else-if="tab === 'domains'" :page="data" :base="base" />
            <WebsiteDatabase v-else-if="tab === 'database'" :page="data" :base="base" />
            <WebsiteBackups v-else-if="tab === 'backups'" :page="data" :base="base" />
            <WebsiteFiles v-else-if="tab === 'files'" :base="base" />
            <WebsiteSettings v-else :page="data" :base="base" />
        </div>
    </div>
</template>
