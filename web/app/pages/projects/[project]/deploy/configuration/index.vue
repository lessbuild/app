<script setup lang="ts">
import type { ConfigurationPage, ConfigurationPlan } from '~/types/deploy';

/**
 * Configuration as code (the Acme theme's configuration page): a YAML document describing environments, planned
 * (nothing changes) with the plan beside it, then created as a review to apply; recent reviews; the IDs its names bind
 * to; and the version 1 workflow format.
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
    return null;
}

/** A review's state, as a badge. */
function reviewBadge(review: ConfigurationPage['reviews'][number]) {
    if (review.applicationId) {
        return { tone: 'green' as const, label: t('Applied') };
    }
    return review.expired ? { tone: 'gray' as const, label: t('Expired') } : { tone: 'blue' as const, label: t('Ready to apply') };
}
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Configuration')" :description="t('Describe environments in a YAML document, see exactly what it would change, then apply it as a review. Objects the document doesn’t mention are left alone.')" />
        <div class="space-y-6">
            <div class="grid gap-6 xl:grid-cols-[1.3fr_1fr]">
                <AcmeCard :title="t('Document')" :description="t('Version 2 documents, as in Deployer. Names in placements, repositories and secret refs are bound to records below.')">
                    <ApiForm :action="`${base}/plan`" :after="planned" class="grid gap-4">
                        <label for="configuration-document" class="sr-only">{{ t('Document (YAML)') }}</label>
                        <textarea id="configuration-document" v-model="document" name="document" rows="20" spellcheck="false" required class="w-full resize-y rounded-xl border border-line bg-zinc-950 p-4 font-mono text-xs leading-5 text-zinc-200 outline-none focus:ring-4 focus:ring-accent/20" />
                        <TextareaField id="configuration-bindings" v-model="bindings" name="bindings" :label="t('Bindings (JSON)')" rows="3" class="font-mono text-sm" :description="t('Map each name to an ID from Bindings.')" spellcheck="false" />
                        <div class="flex flex-wrap gap-2">
                            <SubmitButton variant="secondary">{{ t('Plan changes') }}</SubmitButton>
                        </div>
                    </ApiForm>
                    <ApiForm v-if="data.canManage" :action="`${base}/reviews`" class="mt-4 !flex flex-wrap items-center gap-3 border-t border-line pt-4">
                        <input type="hidden" name="document" :value="document">
                        <input type="hidden" name="bindings" :value="bindings">
                        <SubmitButton :disabled="plan === null">{{ t('Create review') }}</SubmitButton>
                        <span class="text-xs text-muted">{{ plan === null ? t('Plan the document first.') : t('Reviews last 15 minutes; applying one gives a receipt that follows its deploys.') }}</span>
                    </ApiForm>
                </AcmeCard>
                <div class="space-y-6">
                    <AcmeCard id="configuration-plan" :title="t('Plan')" :description="plan ? t('What applying this document would change.') : t('Plan the document to see its changes here.')">
                        <template v-if="plan">
                            <ConfigurationChanges :changes="plan.changes" />
                            <p v-if="!plan.apply_available" class="mt-3 text-sm text-amber-600">{{ t('Some objects exist but configuration doesn’t own them. Add adopt: true to take them over, then plan again.') }}</p>
                        </template>
                        <AcmeEmptyState v-else icon="search" :title="t('Nothing planned yet')" />
                    </AcmeCard>
                    <AcmeCard :title="t('Reviews')" :description="t('Reviews last 15 minutes; applying one gives a receipt that follows its deploys.')" :padded="false">
                        <p v-if="data.reviews.length === 0" class="px-5 pb-5 text-sm text-muted sm:px-6">{{ t('No reviews yet.') }}</p>
                        <ul v-else class="divide-y divide-line text-sm">
                            <li v-for="review in data.reviews" :key="review.id" class="flex flex-wrap items-center gap-3 px-5 py-3 sm:px-6">
                                <NuxtLink :to="review.applicationId ? `/projects/${project.id}/deploy/configuration/applications/${review.applicationId}` : `/projects/${project.id}/deploy/configuration/reviews/${review.id}`" class="font-medium text-ink hover:underline">{{ t('Review #:id', { id: review.id }) }}</NuxtLink>
                                <span class="text-muted">{{ review.requester }}<template v-if="review.createdAt"> · <RelativeTime :at="review.createdAt" /></template> · {{ tc(':count change|:count changes', review.changes, { count: review.changes }) }}</span>
                                <AcmeBadge :tone="reviewBadge(review).tone" class="ml-auto">{{ reviewBadge(review).label }}</AcmeBadge>
                            </li>
                        </ul>
                    </AcmeCard>
                    <AcmeCard :title="t('Bindings')" :description="t('Placements are websites, repositories are this project’s repositories, and secrets are secret variables.')">
                        <div class="space-y-4 text-xs">
                            <div v-for="list in lists" :key="list.heading">
                                <h3 class="section-label mb-1.5">{{ list.heading }}</h3>
                                <dl class="space-y-1.5">
                                    <div v-for="row in list.rows" :key="row.id" class="flex gap-3"><dt class="w-12 shrink-0 font-mono text-ink">{{ row.id }}</dt><dd class="text-muted">{{ row.label }}</dd></div>
                                    <p v-if="list.rows.length === 0" class="text-muted">{{ t('None') }}</p>
                                </dl>
                            </div>
                        </div>
                    </AcmeCard>
                </div>
            </div>

            <AcmeCard v-if="data.canManage" id="workflow" :title="t('Workflow (version 1)')" :description="t('Deployer’s workflow format: scheduled deploys, scaling, scaling schedules and processes per environment, applied all at once. Also at PUT /api/v1/projects/{project}/workflow.')">
                <ApiForm :action="`${base}/workflow`" class="grid gap-3">
                    <TextareaField
                        id="configuration-workflow"
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
            </AcmeCard>
        </div>
    </div>
</template>
