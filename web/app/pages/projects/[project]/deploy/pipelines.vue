<script setup lang="ts">
import type { PipelinesPage } from '~/types/deploy';
import type { Tone } from '~/types/ui';

/** Pipelines: repositories deployed in order, each waiting for the one before to go live, with their recent runs. */
definePageMeta({ layout: 'app', service: 'deploy' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<PipelinesPage>(() => `/projects/${route.params.project}/deploy/pipelines`);
const project = computed(() => data.value.overview.project);
const base = computed(() => `/api/app/projects/${project.value.id}/deploy/pipelines`);
const runs = computed<Record<string, { tone: Tone; label: string }>>(() => ({
    succeeded: { tone: 'success', label: t('Succeeded') },
    failed: { tone: 'danger', label: t('Failed') },
    running: { tone: 'info', label: t('Running') },
}));
const steps = computed(() => Array.from({ length: Math.min(4, data.value.repositories.length) }, (_, index) => index));
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Pipelines')" :description="t('Deploy several repositories in order, such as the API and then the frontend. Each waits for the one before to go live, and the run stops if one fails.')">
            <template v-if="data.canManage && data.repositories.length >= 2" #actions>
                <UiButton variant="primary" :to="{ query: { dialog: 'new-pipeline' } }"><Icon name="plus" class="h-4 w-4" />{{ t('New pipeline') }}</UiButton>
            </template>
        </ProjectHeader>

        <EmptyState v-if="data.pipelines.length === 0" icon="view-grid" :title="t('No pipelines yet')" :description="t('Connect two or more repositories, then chain them here.')" />

        <section v-for="pipeline in data.pipelines" :key="pipeline.id" class="ui-card grid gap-4 p-5 sm:p-6" :aria-labelledby="`pipeline-${pipeline.id}`">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 :id="`pipeline-${pipeline.id}`" class="text-lg font-extrabold text-ink">{{ pipeline.name }}</h2>
                <div class="flex gap-2">
                    <ApiForm :action="`${base}/${pipeline.id}/run`"><SubmitButton size="sm">{{ t('Run') }}</SubmitButton></ApiForm>
                    <DeleteDialog v-if="data.canManage" :id="`delete-pipeline-${pipeline.id}`" :title="t('Delete :name?', { name: pipeline.name })" :action="`${base}/${pipeline.id}`">
                        <template #trigger="{ open }"><UiButton variant="quiet" size="sm" @click="open">{{ t('Delete') }}</UiButton></template>
                    </DeleteDialog>
                </div>
            </div>
            <ol class="flex flex-wrap items-center gap-2 text-sm" :aria-label="t('Steps')">
                <li v-for="(step, index) in pipeline.steps" :key="`${step.id}-${index}`" class="flex items-center gap-2">
                    <Icon v-if="index > 0" name="arrow-right" class="h-4 w-4 text-muted" />
                    <span class="rounded-control border border-line px-2 py-1">
                        {{ step.name ?? t('Removed repository') }} <span v-if="step.environment" class="text-xs text-muted">{{ step.environment }}</span>
                    </span>
                </li>
            </ol>
            <ul v-if="pipeline.runs.length > 0" class="grid gap-2 border-t border-line pt-3 text-sm" :aria-label="t('Recent runs')">
                <li v-for="run in pipeline.runs" :key="run.id" class="flex flex-wrap items-center gap-2">
                    <Badge :tone="runs[run.status]?.tone ?? 'info'">{{ runs[run.status]?.label ?? run.status }}</Badge>
                    <RelativeTime v-if="run.createdAt" :at="run.createdAt" class="text-muted" />
                    <NuxtLink v-for="build in run.builds" :key="build.id" :to="`/projects/${project.id}/deploy/builds/${build.id}`" class="inline-flex items-center gap-1 font-mono text-xs text-primary hover:underline">
                        #{{ build.id }} <BuildStatusBadge v-if="build.status" :status="build.status" />
                    </NuxtLink>
                    <span v-if="run.failure" class="text-xs text-danger">{{ run.failure }}</span>
                </li>
            </ul>
        </section>

        <FormDialog
            v-if="data.canManage && data.repositories.length >= 2"
            id="new-pipeline"
            :title="t('New pipeline')"
            :description="t('Pick the repositories in the order they should deploy. Each deploys its branch’s latest commit.')"
            :action="base"
            :submit="t('Save pipeline')"
        >
            <InputField name="name" :label="t('Name')" maxlength="80" required placeholder="API then web" autofocus />
            <div class="grid gap-3 sm:grid-cols-2">
                <SelectField
                    v-for="step in steps"
                    :id="`pipeline-step-${step + 1}`"
                    :key="step"
                    :name="`steps[${step}]`"
                    error-key="steps"
                    :label="t('Step :step', { step: step + 1 })"
                    :placeholder="t('None')"
                    :options="data.repositories"
                />
            </div>
        </FormDialog>
    </div>
</template>
