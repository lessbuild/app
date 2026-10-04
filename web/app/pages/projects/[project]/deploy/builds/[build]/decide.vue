<script setup lang="ts">
import type { DecisionPage } from '~/types/deploy';

/**
 * Approve or reject a deploy waiting for approval, from the buttons in a chat message (`?decision=approve|reject`).
 * Opening the page changes nothing; the decision is confirmed here.
 */
definePageMeta({ layout: 'app', service: 'deploy' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<DecisionPage>(() => `/projects/${route.params.project}/deploy/builds/${route.params.build}/decide`);
const decision = computed(() => (route.query.decision === 'reject' ? 'reject' : 'approve'));
const build = computed(() => data.value.build);
const project = computed(() => data.value.overview.project);
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader
            :overview="data.overview"
            :title="decision === 'approve' ? t('Approve deploy #:id', { id: build.id }) : t('Reject deploy #:id', { id: build.id })"
            :description="t(':repository to :environment', { repository: build.repository, environment: build.environment ?? '—' })"
        />
        <section class="ui-card grid max-w-2xl gap-4 p-5 sm:p-6">
            <dl class="grid gap-3 text-sm sm:grid-cols-3">
                <div><dt class="text-xs text-muted">{{ t('Started by') }}</dt><dd class="mt-1">{{ build.requester ?? t('A push') }}</dd></div>
                <div><dt class="text-xs text-muted">{{ t('Commit') }}</dt><dd class="mt-1 break-all font-mono text-xs">{{ build.revision ?? t('Latest on :branch', { branch: build.branch }) }}</dd></div>
                <div><dt class="text-xs text-muted">{{ t('Status') }}</dt><dd class="mt-1"><BuildStatusBadge :status="build.status" /></dd></div>
            </dl>
            <p v-if="build.commitMessage" class="whitespace-pre-line text-sm text-muted">{{ build.commitMessage }}</p>

            <Alert v-if="!build.awaitingApproval" tone="info">{{ t('This deploy isn’t waiting for approval any more.') }}</Alert>
            <Alert v-else-if="!data.canApprove" tone="warning">{{ data.reason || t('You can’t approve this deploy.') }}</Alert>
            <ApiForm v-else :action="`/api/app/projects/${project.id}/deploy/builds/${build.id}/review`" class="grid gap-3">
                <input type="hidden" name="decision" :value="decision">
                <InputField name="note" :label="t('Note (optional)')" maxlength="1000" />
                <div class="flex flex-wrap gap-2">
                    <SubmitButton :variant="decision === 'approve' ? 'primary' : 'danger'">{{ decision === 'approve' ? t('Approve and deploy') : t('Reject') }}</SubmitButton>
                    <UiButton :to="{ query: { decision: decision === 'approve' ? 'reject' : 'approve' } }" variant="quiet">{{ decision === 'approve' ? t('Reject instead') : t('Approve instead') }}</UiButton>
                </div>
            </ApiForm>
            <p class="text-sm"><NuxtLink :to="`/projects/${project.id}/deploy/builds/${build.id}`" class="font-bold text-primary hover:underline">{{ t('See the whole deploy') }}</NuxtLink></p>
        </section>
    </div>
</template>
