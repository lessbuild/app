<script setup lang="ts">
/**
 * The frame for sign-in pages: the brand, one card with the heading, and an optional line underneath (the `footer`
 * slot). Also sets the document title.
 */
const props = withDefaults(defineProps<{ heading: string; title?: string; eyebrow?: string; description?: string; status?: string | null }>(), {
    title: undefined, eyebrow: undefined, description: undefined, status: null,
});
const { t } = useT();

useHead({ title: () => props.title ?? props.heading });
</script>

<template>
    <main id="main-content" class="mx-auto grid min-h-screen w-full max-w-md place-items-center px-4 py-10 sm:px-6">
        <div class="w-full">
            <NuxtLink to="/" external class="mb-6 inline-flex items-center gap-3 rounded-control text-lg font-extrabold tracking-tight text-ink focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-focus">
                <span class="grid h-10 w-10 place-items-center rounded-card bg-ink text-surface" aria-hidden="true">↗</span>
                <span>BuildPusher</span>
            </NuxtLink>
            <div class="ui-card p-6 sm:p-8">
                <p v-if="eyebrow" class="ui-eyebrow">{{ eyebrow }}</p>
                <h1 class="mt-2 text-2xl font-extrabold tracking-tight text-ink">{{ heading }}</h1>
                <p v-if="description" class="mt-2 text-sm leading-6 text-muted">{{ description }}</p>
                <div v-if="status" class="ui-alert ui-alert--success ui-alert-success mt-5" role="status">{{ status }}</div>
                <div class="mt-6"><slot /></div>
            </div>
            <div v-if="$slots.footer" class="mt-5 text-center text-sm text-muted"><slot name="footer" /></div>
            <p class="mt-8 text-center text-xs text-subtle">
                <NuxtLink to="/privacy" class="hover:text-ink">{{ t('Privacy') }}</NuxtLink>
                <span aria-hidden="true"> · </span>
                <NuxtLink to="/terms" class="hover:text-ink">{{ t('Terms') }}</NuxtLink>
                <span aria-hidden="true"> · </span>
                <NuxtLink to="/help" class="hover:text-ink">{{ t('Help centre') }}</NuxtLink>
            </p>
        </div>
    </main>
</template>
