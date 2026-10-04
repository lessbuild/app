<script setup lang="ts">
import type { RepositoriesPage, RepositoryFormOptions } from '~/types/deploy';

/** Deploy's front page: the project's repositories, where each deploys and how its last deploy went. */
definePageMeta({ layout: 'app', service: 'deploy' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<RepositoriesPage>(() => `/projects/${route.params.project}/deploy`);
const project = computed(() => data.value.overview.project);
const options = ref<RepositoryFormOptions | null>(null);

// The connect dialog's choices load when it opens (a link to ?dialog=connect-repository opens it too).
watch(() => route.query.dialog, async (dialog) => {
    if (dialog === 'connect-repository' && options.value === null && data.value.canCreate) {
        options.value = (await send<{ options: RepositoryFormOptions }>('GET', `/projects/${project.value.id}/deploy/repositories/create`)).options;
    }
}, { immediate: import.meta.client });
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Repositories')" :description="t('Git repositories that deploy to your websites. Each deploy is a new release; the previous ones stay on the server for rollbacks.')">
            <template #actions>
                <a href="/api/app/account/inventory/repositories.csv" class="ui-btn ui-btn-quiet ui-btn-sm" download>{{ t('Export CSV') }}</a>
                <UiButton v-if="data.canCreate" variant="primary" :to="{ query: { dialog: 'connect-repository' } }"><Icon name="plus" class="h-4 w-4" />{{ t('Connect a repository') }}</UiButton>
            </template>
        </ProjectHeader>

        <EmptyState v-if="data.repositories.length === 0" icon="cloud-upload" :title="t('No repositories yet')" :description="t('Connect a GitHub, GitLab or Bitbucket repository to deploy it to one of your websites.')">
            <template v-if="data.canCreate" #action>
                <UiButton variant="primary" :to="{ query: { dialog: 'connect-repository' } }">{{ t('Connect a repository') }}</UiButton>
            </template>
        </EmptyState>
        <DataTable v-else :caption="t('Repositories')">
            <template #head>
                <tr><th scope="col">{{ t('Repository') }}</th><th scope="col">{{ t('Deploys to') }}</th><th scope="col">{{ t('Last deploy') }}</th></tr>
            </template>
            <tr v-for="repository in data.repositories" :key="repository.id">
                <td>
                    <NuxtLink :to="`/projects/${project.id}/deploy/repositories/${repository.id}`" class="font-bold text-primary hover:underline">{{ repository.name }}</NuxtLink>
                    <span class="block font-mono text-xs text-muted">{{ repository.url }} · {{ repository.branch }}</span>
                </td>
                <td>{{ repository.website ?? '—' }} <span v-if="repository.environment" class="text-muted">· {{ repository.environment }}</span></td>
                <td>
                    <NuxtLink v-if="repository.latestBuild" :to="`/projects/${project.id}/deploy/builds/${repository.latestBuild.id}`" class="inline-flex items-center gap-2">
                        <BuildStatusBadge :status="repository.latestBuild.status" />
                        <RelativeTime v-if="repository.latestBuild.createdAt" :at="repository.latestBuild.createdAt" class="text-xs text-muted" />
                    </NuxtLink>
                    <span v-else class="text-muted">{{ t('Never') }}</span>
                </td>
            </tr>
        </DataTable>

        <UiDialog v-if="data.canCreate" id="connect-repository" :title="t('Connect a repository')" :description="t('It’s cloned over HTTPS with the provider’s token and deployed to the website you choose.')" size="large">
            <ApiForm v-if="options" :action="`/api/app/projects/${project.id}/deploy/repositories`" class="grid gap-5">
                <RepositoryFields :options="options" />
                <div class="flex justify-end"><SubmitButton>{{ t('Connect repository') }}</SubmitButton></div>
            </ApiForm>
            <p v-else class="text-sm text-muted" role="status">{{ t('Loading…') }}</p>
        </UiDialog>
    </div>
</template>
