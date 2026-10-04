<script setup lang="ts">
import type { ServiceCopy } from '~/types/site';

/** A service in the product suite: what it's for, its highlights and a link to its page. */
defineProps<{ serviceKey: string; name: string; copy: ServiceCopy }>();
const { t } = useT();
</script>

<template>
    <article :class="['ui-card ui-card--interactive flex h-full flex-col p-5 sm:p-6', `product-preview-${copy.accent}`]">
        <span :class="['grid size-10 place-items-center rounded-xl', `product-icon-${copy.accent}`]" aria-hidden="true"><Icon :name="copy.icon" class="size-5" /></span>
        <p :class="['mt-4 text-xs font-extrabold uppercase tracking-[0.14em]', `product-accent-${copy.accent}`]">{{ copy.eyebrow }}</p>
        <h3 class="mt-2 text-xl font-extrabold tracking-tight text-ink">{{ name }}</h3>
        <p class="mt-3 text-sm leading-6 text-muted">{{ copy.card_summary }}</p>
        <ul class="mt-5 grid gap-3" :aria-label="t(':service highlights', { service: name })">
            <li v-for="feature in copy.card_features" :key="feature" class="flex items-start gap-3 text-sm font-semibold text-ink">
                <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-emphasis text-emphasis-ink" aria-hidden="true"><Icon name="check" class="size-3" /></span>
                <span>{{ feature }}</span>
            </li>
        </ul>
        <div class="mt-auto pt-6">
            <NuxtLink :to="`/features/${serviceKey}`" class="ui-btn ui-btn-secondary w-full justify-center">{{ t('Explore :service', { service: name }) }} <Icon name="arrow-right" class="size-4" /></NuxtLink>
        </div>
    </article>
</template>
