<script setup lang="ts">
/** The plan-limit message Laravel returns as the `plan` error, with the way to more. */
defineProps<{ billingUrl?: string }>();
const form = useForm();
const { t } = useT();
</script>

<template>
    <div v-if="form.errors.plan?.[0]" class="ui-alert ui-alert--warning ui-alert-warning" role="alert">
        {{ form.errors.plan[0] }} <NuxtLink v-if="billingUrl" :to="billingUrl" class="font-semibold underline">{{ t('See plans with more') }}</NuxtLink>
    </div>
</template>
