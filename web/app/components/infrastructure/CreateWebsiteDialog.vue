<script setup lang="ts">
import type { WebsiteFormOptions } from '~/types/infrastructure';

/**
 * Creating a website, in a dialog over any page of the project (`?dialog=create-website`). It loads the servers and
 * choices when it opens unless the page passes them; `back` is the setup guide to return to afterwards. With no app
 * server ready it offers the create-server dialog, which the page renders too.
 */
const props = defineProps<{ projectId: string; options?: WebsiteFormOptions; back?: string | null }>();
const { t } = useT();
const route = useRoute();
const { data, failed } = useDialogData<{ options: WebsiteFormOptions }>('create-website', () => `/projects/${props.projectId}/infrastructure/websites/create`, () => (props.options ? { options: props.options } : undefined));
</script>

<template>
    <FormDialog v-if="data && data.options.hosts.length > 0" id="create-website" :title="t('Create a website')" :description="t('We set up the Caddy site, a MySQL database and user, and the .env file.')" :action="`/api/app/projects/${projectId}/infrastructure/websites`" :submit="t('Create website')" size="large">
        <input v-if="back" type="hidden" name="_return" :value="back">
        <PlanLimitAlert billing-url="/account/billing" />
        <WebsiteFields :options="data.options" />
    </FormDialog>
    <UiDialog v-else id="create-website" :title="t('Create a website')">
        <EmptyState v-if="data" icon="server" :title="t('No app servers ready')" :description="t('Websites need an active app server with MySQL. Create one first.')">
            <template #action><UiButton variant="primary" :to="{ query: { ...route.query, dialog: 'create-server' } }">{{ t('Create a server') }}</UiButton></template>
        </EmptyState>
        <DialogLoading v-else :failed="failed" />
    </UiDialog>
</template>
