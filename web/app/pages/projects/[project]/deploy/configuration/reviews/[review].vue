<script setup lang="ts">
import type { ConfigurationPlan } from '~/types/deploy';
import type { ProjectOverview } from '~/types/projects';

/** A configuration review: what applying it changes, and applying it while it's still current. */
definePageMeta({ layout: 'app', service: 'deploy' });
const { t, dateTime } = useT();
const route = useRoute();
type ReviewPage = {
    overview: ProjectOverview;
    review: { id: string; requester: string; expiresAt: string; expired: boolean; plan: ConfigurationPlan; applicationId: string | null };
    canApply: boolean;
};
const { data } = await useApi<ReviewPage>(() => `/projects/${route.params.project}/deploy/configuration/reviews/${route.params.review}`);
const review = computed(() => data.value.review);
const project = computed(() => data.value.overview.project);
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader
            :overview="data.overview"
            :title="t('Review #:id', { id: review.id })"
            :description="t('Created by :name. It can be applied until :time, if nothing has changed since.', { name: review.requester, time: dateTime(review.expiresAt) })"
        />
        <ConfigurationChanges :changes="review.plan.changes" />
        <UiButton v-if="review.applicationId" :to="`/projects/${project.id}/deploy/configuration/applications/${review.applicationId}`">{{ t('See what applying it did') }}</UiButton>
        <Alert v-else-if="review.expired" tone="info">{{ t('This review has expired. Create a new one from the Configuration page.') }}</Alert>
        <Alert v-else-if="!review.plan.apply_available" tone="warning">{{ t('Some objects need adopt: true before this can be applied.') }}</Alert>
        <ApiForm v-else-if="data.canApply" :action="`/api/app/projects/${project.id}/deploy/configuration/reviews/${review.id}/apply`">
            <SubmitButton>{{ t('Apply configuration') }}</SubmitButton>
        </ApiForm>
        <p v-else class="text-sm text-muted">{{ t('Only :name can apply this review.', { name: review.requester }) }}</p>
    </div>
</template>
