<script setup lang="ts">
import type { PipelinesPage } from '~/types/deploy';

/**
 * Pipelines (the Acme theme's pipelines page): repositories deployed in order, each waiting for the one before to go
 * live, with their recent runs. A new pipeline takes up to eight steps, added, removed and reordered as you go.
 */
definePageMeta({ layout: 'app', service: 'deploy' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<PipelinesPage>(() => `/projects/${route.params.project}/deploy/pipelines`);
const project = computed(() => data.value.overview.project);
const base = computed(() => `/api/app/projects/${project.value.id}/deploy/pipelines`);
const runs = computed<Record<string, { tone: 'green' | 'red' | 'blue'; label: string }>>(() => ({
    succeeded: { tone: 'green', label: t('Succeeded') },
    failed: { tone: 'red', label: t('Failed') },
    running: { tone: 'blue', label: t('Running') },
}));
const draft = ref<string[]>(['', '']);

/**
 * Move a step up or down the new pipeline.
 *
 * @param index The step.
 * @param by -1 for up, 1 for down.
 */
function move(index: number, by: number) {
    const steps = [...draft.value];
    [steps[index], steps[index + by]] = [steps[index + by]!, steps[index]!];
    draft.value = steps;
}
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Pipelines')" :description="t('Deploy several repositories in order, such as the API and then the frontend. Each waits for the one before to go live, and the run stops if one fails.')">
            <template v-if="data.canManage && data.repositories.length >= 2" #actions>
                <AcmeBtn variant="primary" icon="plus" :to="{ query: { dialog: 'new-pipeline' } }">{{ t('New pipeline') }}</AcmeBtn>
            </template>
        </ProjectHeader>
        <div class="space-y-6">
            <AcmeEmptyCard v-if="data.pipelines.length === 0" icon="branch" :title="t('No pipelines yet')" :description="t('Connect two or more repositories, then chain them here.')" />

            <AcmeCard v-for="pipeline in data.pipelines" :key="pipeline.id" :title="pipeline.name">
                <template #action>
                    <div class="flex gap-2">
                        <ApiForm :action="`${base}/${pipeline.id}/run`" class="!block"><SubmitButton size="sm">{{ t('Run') }}</SubmitButton></ApiForm>
                        <DeleteDialog v-if="data.canManage" :id="`delete-pipeline-${pipeline.id}`" :title="t('Delete :name?', { name: pipeline.name })" :action="`${base}/${pipeline.id}`">
                            <template #trigger="{ open }"><AcmeBtn size="sm" variant="ghost" icon="trash" :label="t('Delete :name', { name: pipeline.name })" @click="open" /></template>
                        </DeleteDialog>
                    </div>
                </template>
                <ol class="flex flex-wrap items-center gap-2" :aria-label="t('Steps')">
                    <li v-for="(step, index) in pipeline.steps" :key="`${step.id}-${index}`" class="flex items-center gap-2">
                        <span class="flex items-center gap-2 rounded-xl border border-line bg-black/[.02] px-3 py-2 text-sm dark:bg-white/[.03]">
                            <span class="grid size-5 place-items-center rounded-full bg-accent text-[0.625rem] font-semibold text-accent-fg">{{ index + 1 }}</span>
                            <span class="font-mono text-ink">{{ step.name ?? t('Removed repository') }}</span>
                            <span v-if="step.environment" class="text-xs text-muted">{{ step.environment }}</span>
                        </span>
                        <AcmeIcon v-if="index < pipeline.steps.length - 1" name="arrowRight" :size="16" class="text-muted" />
                    </li>
                </ol>
                <h3 class="section-label mt-6">{{ t('Recent runs') }}</h3>
                <ul v-if="pipeline.runs.length > 0" class="mt-2 divide-y divide-line text-sm">
                    <li v-for="run in pipeline.runs" :key="run.id" class="flex flex-wrap items-center gap-3 py-2.5">
                        <AcmeBadge :tone="runs[run.status]?.tone ?? 'blue'" dot>{{ runs[run.status]?.label ?? run.status }}</AcmeBadge>
                        <RelativeTime v-if="run.createdAt" :at="run.createdAt" class="text-ink" />
                        <NuxtLink v-for="build in run.builds" :key="build.id" :to="`/projects/${project.id}/deploy/builds/${build.id}`" class="font-mono text-xs text-muted hover:text-ink hover:underline">#{{ build.id }}</NuxtLink>
                        <span v-if="run.failure" class="w-full text-xs text-rose-600 sm:ml-auto sm:w-auto">{{ run.failure }}</span>
                    </li>
                </ul>
                <p v-else class="mt-2 text-sm text-muted">{{ t('Not run yet.') }}</p>
            </AcmeCard>
        </div>

        <FormDialog
            v-if="data.canManage && data.repositories.length >= 2"
            id="new-pipeline"
            :title="t('New pipeline')"
            :description="t('Pick the repositories in the order they should deploy. Each deploys its branch’s latest commit.')"
            :action="base"
            :submit="t('Save pipeline')"
            size="large"
        >
            <InputField id="pipeline-name" name="name" :label="t('Name')" maxlength="80" required placeholder="API then web" autofocus />
            <div v-for="(step, index) in draft" :key="index" class="flex items-end gap-2">
                <SelectField :id="`pipeline-step-${index + 1}`" v-model="draft[index]" :name="`steps[${index}]`" error-key="steps" :label="t('Step :step', { step: index + 1 })" :placeholder="t('Choose a repository')" :options="data.repositories" class="flex-1" />
                <AcmeBtn variant="ghost" icon="arrowUp" :label="t('Move step :step up', { step: index + 1 })" :disabled="index === 0" @click="move(index, -1)" />
                <AcmeBtn variant="ghost" icon="arrowDown" :label="t('Move step :step down', { step: index + 1 })" :disabled="index === draft.length - 1" @click="move(index, 1)" />
                <AcmeBtn variant="ghost" icon="trash" :label="t('Remove step :step', { step: index + 1 })" :disabled="draft.length <= 2" @click="draft.splice(index, 1)" />
            </div>
            <div><AcmeBtn size="sm" icon="plus" :disabled="draft.length >= 8" @click="draft.push('')">{{ t('Add step') }}</AcmeBtn></div>
        </FormDialog>
    </div>
</template>
