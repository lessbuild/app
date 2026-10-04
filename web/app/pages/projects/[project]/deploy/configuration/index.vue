<script setup lang="ts">
import type { ConfigurationPage, ConfigurationPlan } from '~/types/deploy';

/**
 * Configuration as code: a YAML document describing environments, planned (nothing changes), then created as a
 * review to apply; the IDs its names bind to; recent reviews; and the version 1 workflow format.
 */
definePageMeta({ layout: 'app', service: 'deploy' });
const { t, tc } = useT();
const route = useRoute();
const { data } = await useApi<ConfigurationPage>(() => `/projects/${route.params.project}/deploy/configuration`);
const project = computed(() => data.value.overview.project);
const base = computed(() => `/api/app/projects/${project.value.id}/deploy/configuration`);
const example = "version: 2\nenvironments:\n  production:\n    type: production\n    placement: web\n    runtime:\n      type: php\n    processes:\n      queue: { type: worker, command: 'php artisan queue:work', replicas: 2 }\n    resources:\n      database: { type: mysql, managed: true }\n    variables:\n      STRIPE_SECRET: { secret_ref: stripe, scope: runtime }\n    deploy:\n      repository: app";
const document = ref(example);
const bindings = ref('{"placements": {"web": 1}, "repositories": {"app": 1}, "secrets": {"stripe": 1}}');
const workflow = ref(data.value.workflow ?? '');
const plan = ref<ConfigurationPlan | null>(null);
const lists = computed(() => [
    { heading: t('Websites'), rows: data.value.websites },
    { heading: t('Repositories'), rows: data.value.repositories },
    { heading: t('Secrets'), rows: data.value.secrets },
]);

/** Show the plan the API worked out; nothing has changed. */
function planned(result: Record<string, unknown>): null {
    plan.value = (result.plan as ConfigurationPlan | undefined) ?? null;
    nextTick(() => document.value && window.document.getElementById('configuration-plan')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    return null;
}

/** A review's state, as a badge. */
function reviewBadge(review: ConfigurationPage['reviews'][number]) {
    if (review.applicationId) {
        return { tone: 'success' as const, label: t('Applied') };
    }
    return review.expired ? { tone: 'neutral' as const, label: t('Expired') } : { tone: 'info' as const, label: t('Ready to apply') };
}
</script>

<template>
    <div class="space-y-10">
        <ProjectHeader
            :overview="data.overview"
            :title="t('Configuration')"
            :description="t('Describe environments in a YAML document, see exactly what it would change, then apply it as a review. Objects the document doesn’t mention are left alone.')"
        />

        <section v-if="plan" id="configuration-plan" class="ui-card grid gap-4 p-5" aria-labelledby="plan-heading">
            <h2 id="plan-heading" class="font-extrabold text-ink">{{ t('Plan') }}</h2>
            <ConfigurationChanges :changes="plan.changes" />
            <p v-if="!plan.apply_available" class="text-sm text-warning">{{ t('Some objects exist but configuration doesn’t own them. Add adopt: true to take them over, then plan again.') }}</p>
        </section>

        <SettingsSection :title="t('Document')" :description="t('Version 2 documents, as in Deployer. Names in placements, repositories and secret refs are bound to records below.')">
            <div class="grid gap-4 p-4 sm:p-6">
                <ApiForm :action="`${base}/plan`" :after="planned" class="grid gap-4">
                    <TextareaField v-model="document" name="document" :label="t('Document (YAML)')" rows="16" class="font-mono text-sm" required spellcheck="false" />
                    <TextareaField v-model="bindings" name="bindings" :label="t('Bindings (JSON)')" rows="4" class="font-mono text-sm" :description="t('Map each name to an ID from the lists below.')" spellcheck="false" />
                    <div class="flex flex-wrap gap-2">
                        <SubmitButton variant="secondary">{{ t('Plan changes') }}</SubmitButton>
                    </div>
                </ApiForm>
                <ApiForm v-if="data.canManage" :action="`${base}/reviews`" class="flex flex-wrap items-center gap-3 border-t border-line pt-4">
                    <input type="hidden" name="document" :value="document">
                    <input type="hidden" name="bindings" :value="bindings">
                    <SubmitButton>{{ t('Create review') }}</SubmitButton>
                    <span class="text-xs text-muted">{{ t('Reviews last 15 minutes; applying one gives a receipt that follows its deploys.') }}</span>
                </ApiForm>
            </div>
        </SettingsSection>

        <SettingsSection :title="t('Binding IDs')" :description="t('Placements are websites, repositories are this project’s repositories, and secrets are secret variables.')">
            <div class="grid gap-4 p-4 text-sm sm:grid-cols-3 sm:p-6">
                <div v-for="list in lists" :key="list.heading">
                    <h3 class="mb-2 font-bold text-ink">{{ list.heading }}</h3>
                    <ul class="grid gap-1">
                        <li v-for="row in list.rows" :key="row.id"><span class="font-mono text-xs text-muted">{{ row.id }}</span> {{ row.label }}</li>
                        <li v-if="list.rows.length === 0" class="text-muted">{{ t('None') }}</li>
                    </ul>
                </div>
            </div>
        </SettingsSection>

        <SettingsSection :title="t('Recent reviews')" :description="t('Reviews last 15 minutes; applying one gives a receipt that follows its deploys.')">
            <p v-if="data.reviews.length === 0" class="p-4 text-sm text-muted sm:p-6">{{ t('No reviews yet.') }}</p>
            <ul v-else class="divide-y divide-line">
                <li v-for="review in data.reviews" :key="review.id" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm sm:px-6">
                    <NuxtLink
                        :to="review.applicationId ? `/projects/${project.id}/deploy/configuration/applications/${review.applicationId}` : `/projects/${project.id}/deploy/configuration/reviews/${review.id}`"
                        class="font-bold text-primary hover:underline"
                    >
                        {{ t('Review #:id', { id: review.id }) }}
                    </NuxtLink>
                    <span class="text-muted">
                        {{ review.requester }}<template v-if="review.createdAt"> · <RelativeTime :at="review.createdAt" /></template> · {{ tc(':count change|:count changes', review.changes, { count: review.changes }) }}
                    </span>
                    <Badge :tone="reviewBadge(review).tone">{{ reviewBadge(review).label }}</Badge>
                </li>
            </ul>
        </SettingsSection>

        <SettingsSection
            v-if="data.canManage"
            id="workflow"
            :title="t('Workflow (version 1)')"
            :description="t('Deployer’s workflow format: scheduled deploys, scaling, scaling schedules and processes per environment, applied all at once. Also at PUT /api/v1/projects/{project}/workflow.')"
        >
            <ApiForm :action="`${base}/workflow`" class="grid gap-3 p-4 sm:p-6">
                <TextareaField
                    v-model="workflow"
                    name="workflow"
                    :label="t('Workflow YAML')"
                    rows="10"
                    class="font-mono text-sm"
                    spellcheck="false"
                    :placeholder="'version: 1\nenvironments:\n  production:\n    deployment: { cron: \'0 3 * * *\', timezone: UTC }'"
                />
                <div><SubmitButton variant="secondary">{{ t('Apply workflow') }}</SubmitButton></div>
            </ApiForm>
        </SettingsSection>
    </div>
</template>
