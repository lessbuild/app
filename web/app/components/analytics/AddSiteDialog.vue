<script setup lang="ts">
/**
 * Adding an Analytics site, in a dialog over any page of the project (`?dialog=add-site`). It loads the time zones when
 * it opens unless the page passes them; `back` is the setup guide to return to afterwards.
 */
const props = defineProps<{ projectId: string; timezones?: string[]; back?: string | null }>();
const { t } = useT();
const { data, failed } = useDialogData<{ timezones: string[] }>('add-site', () => `/projects/${props.projectId}/analytics/sites`, () => (props.timezones ? { timezones: props.timezones } : undefined));
</script>

<template>
    <FormDialog v-if="data" id="add-site" follow :title="t('Add a site')" :description="t('A hostname must be a verified domain of this project (or a subdomain of one) before the site collects.')" :action="`/api/app/projects/${projectId}/analytics/sites`" :submit="t('Add site')" size="wide">
        <input v-if="back" type="hidden" name="_return" :value="back">
        <SiteFields id="new-site" :timezones="data.timezones" />
    </FormDialog>
    <UiDialog v-else id="add-site" follow :title="t('Add a site')"><DialogLoading :failed="failed" /></UiDialog>
</template>
