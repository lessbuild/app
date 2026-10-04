<script setup lang="ts">
import type { NuxtError } from '#app';

/** A page that isn't there (or isn't the person's to see), or a failure (the Acme theme's error page). */
const props = defineProps<{ error: NuxtError }>();
const { t } = useT();
const missing = computed(() => props.error.statusCode === 404);

useHead({ title: () => (missing.value ? t('Page not found') : t('Something went wrong')), htmlAttrs: { 'data-frame': 'site' } });
</script>

<template>
    <main id="main-content" class="grid min-h-dvh place-items-center bg-page p-10">
        <div class="max-w-sm text-center">
            <p class="text-6xl font-semibold tracking-tight text-muted/40">{{ error.statusCode }}</p>
            <h1 class="mt-4 text-2xl font-semibold tracking-tight text-ink">{{ missing ? t('Page not found') : t('Something went wrong') }}</h1>
            <p class="mt-2 text-muted">{{ missing ? t('The page may have moved, or you may not have access to it.') : t('Try again in a moment. If it keeps happening, let us know.') }}</p>
            <div class="mt-6 flex flex-wrap justify-center gap-2">
                <AcmeBtn icon="search" @click="clearError({ redirect: '/help' })">{{ t('Help centre') }}</AcmeBtn>
                <AcmeBtn variant="primary" icon="home" @click="clearError({ redirect: '/dashboard' })">{{ t('Go to your projects') }}</AcmeBtn>
            </div>
        </div>
    </main>
</template>
