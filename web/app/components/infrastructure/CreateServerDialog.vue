<script setup lang="ts">
/** Creating a server, in a dialog over any page of the project (`?dialog=create-server`). The form loads its own choices. */
defineProps<{ projectId: string }>();
const { t } = useT();
</script>

<template>
    <UiDialog id="create-server" :title="t('Create a server')" :description="t('The server provisions itself with the software its type needs. It takes about ten minutes.')" size="large">
        <ServerCreateForm :project-id="projectId" />
    </UiDialog>
</template>
