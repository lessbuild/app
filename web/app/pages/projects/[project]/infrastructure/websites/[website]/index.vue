<script setup lang="ts">
import type { WebsitePage } from '~/types/infrastructure';

/** One website: setup and health, domains, database, backups, files and settings, a tab each. */
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
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="website.name" :description="[website.url, website.server].filter(Boolean).join(' · ')" />
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

        <section class="ui-card grid gap-4 p-5" :aria-busy="website.provisioning || undefined">
            <div class="flex flex-wrap items-center gap-3">
                <WebsiteStatusBadge :status="website.status" />
                <span v-if="website.provisioning" class="text-sm text-muted">{{ t('Stage :stage of :final', { stage: website.stage, final: website.finalStage }) }}</span>
                <a :href="`https://${website.url}`" target="_blank" rel="noopener" class="text-sm font-bold text-primary hover:underline">{{ website.url }}</a>
            </div>
            <ProgressBar v-if="website.provisioning" :value="website.stage" :max="website.finalStage" :label="t('Setup progress')" />
            <Alert v-if="website.status === 'failed'" tone="danger">{{ website.error }}</Alert>
            <Alert v-if="website.cleanupError" tone="danger">{{ t('The copy on the previous server couldn’t be removed: :error', { error: website.cleanupError }) }}</Alert>
            <ApiForm v-if="data.canManage && (website.status === 'failed' || website.cleanupError)" :action="`${base}/retry`"><SubmitButton variant="secondary" size="sm">{{ t('Retry') }}</SubmitButton></ApiForm>
            <dl class="grid gap-4 text-sm sm:grid-cols-3">
                <div><dt class="text-xs text-muted">{{ t('Directory') }}</dt><dd class="mt-1 font-mono">{{ website.directory }}</dd></div>
                <div><dt class="text-xs text-muted">{{ t('Database') }}</dt><dd class="mt-1 font-mono">{{ website.database }}</dd></div>
                <div><dt class="text-xs text-muted">{{ t('Releases kept') }}</dt><dd class="mt-1">{{ website.releaseRetention }}</dd></div>
            </dl>
        </section>

        <PageTabs :tabs="tabs" :current="tab" :label="t('Website sections')" />

        <WebsiteOverview v-if="tab === 'overview'" :page="data" />
        <WebsiteDomains v-else-if="tab === 'domains'" :page="data" :base="base" />
        <WebsiteDatabase v-else-if="tab === 'database'" :page="data" :base="base" />
        <WebsiteBackups v-else-if="tab === 'backups'" :page="data" :base="base" />
        <WebsiteFiles v-else-if="tab === 'files'" :base="base" />
        <WebsiteSettings v-else :page="data" :base="base" />
    </div>
</template>
