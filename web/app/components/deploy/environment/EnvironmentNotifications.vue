<script setup lang="ts">
import type { EnvironmentPage } from '~/types/deploy';

/** Which alert destinations hear about deploys to an environment: going live, failing, waiting for approval. */
defineProps<{ page: EnvironmentPage; base: string }>();
const { t } = useT();
const dialogLink = useDialogLink();
</script>

<template>
    <SettingsSection
        id="notifications"
        :title="t('Deploy notifications')"
        :description="t('Tell Slack, Teams, Discord, a webhook or an inbox when a deploy to this environment goes live, fails or waits for approval. Destinations are set up under Monitoring → Alerts.')"
    >
        <div class="grid gap-4 p-4 sm:p-6">
            <p v-if="page.destinations.length === 0" class="text-sm text-muted">
                {{ t('No alert destinations yet.') }}
                <NuxtLink :to="dialogLink('add-destination')" class="font-bold text-primary underline">{{ t('Add a destination') }}</NuxtLink>
            </p>
            <ApiForm v-else :action="`${base}/notifications`" method="PUT" class="grid gap-4">
                <fieldset
                    v-for="destination in page.destinations"
                    :key="destination.id"
                    :disabled="!page.canManage"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-control border border-line p-3 text-sm"
                >
                    <legend class="sr-only">{{ destination.name }}</legend>
                    <span class="min-w-0"><span class="font-bold">{{ destination.name }}</span> <span class="text-xs text-muted">· {{ destination.type }}<template v-if="!destination.enabled"> · {{ t('paused') }}</template></span></span>
                    <span class="flex flex-wrap gap-x-4">
                        <CheckboxField :id="`deploy-success-${destination.id}`" :name="`destinations[${destination.id}][]`" value="on_success" :label="t('Live')" :checked="destination.onSuccess" />
                        <CheckboxField :id="`deploy-failure-${destination.id}`" :name="`destinations[${destination.id}][]`" value="on_failure" :label="t('Failed')" :checked="destination.onFailure" />
                        <CheckboxField :id="`deploy-approval-${destination.id}`" :name="`destinations[${destination.id}][]`" value="on_approval" :label="t('Needs approval')" :checked="destination.onApproval" />
                    </span>
                </fieldset>
                <div v-if="page.canManage" class="flex justify-end"><SubmitButton variant="secondary">{{ t('Save') }}</SubmitButton></div>
            </ApiForm>
        </div>
    </SettingsSection>
</template>
