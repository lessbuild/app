<script setup lang="ts">
import type { RepositoryFormOptions } from '~/types/deploy';
import type { ProjectOverview } from '~/types/projects';

/**
 * Connect a repository, as its own page: where the GitHub App's list of repositories sends people, with the provider,
 * address and branch filled in from the query.
 */
definePageMeta({ layout: 'app', service: 'deploy' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<{ overview: ProjectOverview; options: RepositoryFormOptions }>(() => `/projects/${route.params.project}/deploy/repositories/create`);
const text = (value: unknown) => (typeof value === 'string' && value !== '' ? value : undefined);
const defaults = computed(() => ({ providerId: text(route.query.provider_id), url: text(route.query.url), branch: text(route.query.branch) }));
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Connect a repository')" :description="t('It’s cloned over HTTPS with the provider’s token and deployed to the website you choose.')" />
        <section class="ui-card p-5 sm:p-6">
            <ApiForm :action="`/api/app/projects/${data.overview.project.id}/deploy/repositories`" class="grid gap-5">
                <RepositoryFields :options="data.options" :defaults="defaults" />
                <div class="flex justify-end"><SubmitButton>{{ t('Connect repository') }}</SubmitButton></div>
            </ApiForm>
        </section>
    </div>
</template>
