<script setup lang="ts">
/** Says a Security feature isn't on the account's plan, with a link to the Security plans. */
defineProps<{ message: string }>();
const { t } = useT();
</script>

<template>
    <Alert tone="info">{{ message }} <NuxtLink to="/account/billing?tab=security" class="ui-link">{{ t('See plans') }}</NuxtLink></Alert>
</template>
