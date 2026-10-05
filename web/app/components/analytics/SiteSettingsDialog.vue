<script setup lang="ts">
import type { SiteSettings } from '~/types/analytics';

/**
 * A site's settings, in a dialog over any page of the project (`?dialog=site-settings`). It loads the site when it opens
 * unless the page passes it, and saving keeps you on the page you were on.
 */
const props = defineProps<{ projectId: string; siteId: string | number; page?: { site: SiteSettings; timezones: string[] } }>();
const { t } = useT();
const { data, failed } = useDialogData<{ site: SiteSettings; timezones: string[] }>('site-settings', () => `/projects/${props.projectId}/analytics/sites/${props.siteId}`, () => props.page);
</script>

<template>
    <FormDialog v-if="data" id="site-settings" :title="t('Site settings')" :description="t('Changing hostnames takes effect for the next visit.')" :action="`/api/app/projects/${projectId}/analytics/sites/${siteId}`" method="PUT" :submit="t('Save')" size="wide">
        <SiteFields id="site" :timezones="data.timezones" :site="data.site" />
    </FormDialog>
    <UiDialog v-else id="site-settings" :title="t('Site settings')"><DialogLoading :failed="failed" /></UiDialog>
</template>
