<script setup lang="ts">
import type { AlertDestinationOptions } from '~/types/monitoring';

/**
 * Adding an alert destination, in a dialog over any of a project's pages (`?dialog=add-destination`): the alerts page,
 * or a monitor's or alert rule's settings when there's nowhere for alerts to go yet. It loads the destination types
 * when it opens unless the page passes them, and keeps the person on the page they were on.
 */
const props = defineProps<{ projectId: string; options?: AlertDestinationOptions | null }>();
const { t } = useT();
const { data, failed } = useDialogData<{ options: AlertDestinationOptions | null }>(
    'add-destination',
    () => `/projects/${props.projectId}/monitoring/alerts`,
    () => (props.options ? { options: props.options } : undefined),
);
</script>

<template>
    <FormDialog
        v-if="data?.options"
        id="add-destination"
        :title="t('Add a destination')"
        :description="t('Account admins manage destinations. Pick which monitors use one on each monitor’s settings.')"
        :action="`/api/app/projects/${projectId}/monitoring/alerts`"
        :submit="t('Add destination')"
    >
        <DestinationFields :options="data.options" />
    </FormDialog>
    <UiDialog v-else id="add-destination" :title="t('Add a destination')">
        <AcmeAlert v-if="data && !data.options" tone="warning">{{ t('Only account admins can add alert destinations.') }}</AcmeAlert>
        <DialogLoading v-else :failed="failed" />
    </UiDialog>
</template>
