<script setup lang="ts">
import type { Dashboard } from '~/types/projects';

/** The account's projects and the team's recent activity: where people land after signing in. */
definePageMeta({ layout: 'app' });
const { t, tc } = useT();
const route = useRoute();
const { data } = await useApi<Dashboard>('/dashboard', () => ({ activity: typeof route.query.activity === 'string' ? route.query.activity : undefined }));
const verified = computed(() => route.query.verified === '1');
</script>

<template>
    <div class="space-y-6">
        <PageHeader :eyebrow="data.account?.name" :title="t('Projects')" :description="t('Each project groups the environments, domains and services of one app or site.')">
            <template v-if="data.canCreate && data.projects.length > 0" #actions>
                <UiButton to="/projects/templates">{{ t('From a template') }}</UiButton>
                <NewProjectDialog>
                    <template #trigger="{ open }">
                        <UiButton variant="primary" @click="open"><Icon name="plus" class="h-4 w-4" />{{ t('New project') }}</UiButton>
                    </template>
                </NewProjectDialog>
            </template>
        </PageHeader>
        <Alert v-if="verified" tone="success" role="status">{{ t('Your email address is verified. Welcome!') }}</Alert>

        <EmptyState v-if="data.account === null" :title="t('You’re not in an account')" :description="t('Ask someone to invite you, or create an account.')" />
        <EmptyState
            v-else-if="data.projects.length === 0"
            icon="layers"
            :title="t('Create your first project')"
            :description="data.canCreate
                ? t('A project is one app or site. You’ll add domains and turn on Deploy, Monitoring, Analytics or Infrastructure next.')
                : t('No projects yet. Someone who manages projects in :account can create one.', { account: data.account.name })"
        >
            <template v-if="data.canCreate" #action>
                <NewProjectDialog>
                    <template #trigger="{ open }">
                        <UiButton variant="primary" @click="open">{{ t('Create a project') }}</UiButton>
                    </template>
                </NewProjectDialog>
                <UiButton to="/projects/templates">{{ t('Start from a template') }}</UiButton>
                <SampleProjectButton />
            </template>
        </EmptyState>
        <div v-else class="grid items-start gap-6 xl:grid-cols-3">
            <ul class="grid gap-4 sm:grid-cols-2 xl:col-span-2" :aria-label="t('Projects')">
                <li v-for="project in data.projects" :key="project.id">
                    <NuxtLink :to="`/projects/${project.id}`" class="ui-card ui-card--interactive block h-full p-5">
                        <p class="text-base font-extrabold text-ink">{{ project.name }}</p>
                        <p v-if="project.description" class="mt-1 line-clamp-2 text-sm text-muted">{{ project.description }}</p>
                        <div class="mt-4 flex flex-wrap items-center gap-1.5">
                            <template v-if="project.serviceNames.length > 0">
                                <Badge v-for="name in project.serviceNames" :key="name" tone="accent">{{ name }}</Badge>
                            </template>
                            <Badge v-else>{{ t('No services yet') }}</Badge>
                            <span class="text-xs text-muted">· {{ tc(':count environment|:count environments', project.environmentCount) }}</span>
                        </div>
                    </NuxtLink>
                </li>
            </ul>
            <ActivityFeed :initial="data.activity" :kind="data.activityKind" :kinds="data.activityKinds" />
        </div>
    </div>
</template>
