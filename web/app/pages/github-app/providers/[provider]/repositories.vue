<script setup lang="ts">
/**
 * The repositories a GitHub App installation can reach, each with a link to connect it in one of the account's
 * projects (the form opens filled in). After installing, the address says so with `?installed=1`.
 */
definePageMeta({ layout: 'app', area: 'account' });
type InstallationRepositories = {
    provider: { id: number; name: string };
    repositories: Array<{ id: number; full_name: string; private: boolean; default_branch: string }>;
    projects: Array<{ id: string; name: string }>;
};
const { t } = useT();
const route = useRoute();
const { data } = await useApi<InstallationRepositories>(() => `/github-app/providers/${route.params.provider}/repositories`);

/** Where connecting a repository in a project starts, with its details filled in. */
function connect(projectId: string, repository: InstallationRepositories['repositories'][number]) {
    const query = new URLSearchParams({ provider_id: String(data.value.provider.id), url: `github.com/${repository.full_name.toLowerCase()}`, branch: repository.default_branch });
    return `/projects/${projectId}/deploy/repositories/create?${query}`;
}
</script>

<template>
    <div class="space-y-6">
        <PageHeader :title="data.provider.name" :description="t('Repositories this GitHub App installation can reach. Connect one in a project to deploy it.')" />
        <Alert v-if="route.query.installed" tone="success" role="status">{{ t('GitHub App installed. Connect one of its repositories from a project’s Deploy section.') }}</Alert>
        <EmptyState v-if="data.repositories.length === 0" icon="cloud-upload" :title="t('No repositories yet')" :description="t('Give the installation access to repositories in your GitHub settings.')" />
        <section v-else class="ui-card overflow-hidden">
            <ul class="divide-y divide-line">
                <li v-for="repository in data.repositories" :key="repository.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                    <span class="font-mono text-sm">
                        {{ repository.full_name }}
                        <span class="text-xs text-muted">{{ repository.private ? t('private') : t('public') }} · {{ repository.default_branch }}</span>
                    </span>
                    <span class="flex flex-wrap gap-1">
                        <UiButton v-for="project in data.projects" :key="project.id" size="sm" variant="quiet" :to="connect(project.id, repository)">{{ t('Connect in :project', { project: project.name }) }}</UiButton>
                    </span>
                </li>
            </ul>
        </section>
    </div>
</template>
