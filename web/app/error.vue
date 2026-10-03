<script setup lang="ts">
import type { NuxtError } from '#app';

/** A page that isn't there (or isn't the person's to see), or a failure, in the sign-in frame. */
const props = defineProps<{ error: NuxtError }>();
const { t } = useT();
const missing = computed(() => props.error.statusCode === 404);
</script>

<template>
    <AuthFrame
        :heading="missing ? t('Page not found') : t('Something went wrong')"
        :description="missing ? t('The page may have moved, or you may not have access to it.') : t('Try again in a moment. If it keeps happening, let us know.')"
    >
        <button type="button" class="ui-btn ui-btn-primary w-full justify-center" @click="clearError({ redirect: '/dashboard' })">{{ t('Go to your projects') }}</button>
    </AuthFrame>
</template>
