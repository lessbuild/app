<script setup lang="ts">
import type { ServiceCopy } from '~/types/site';

/** A service in the product suite (Acme's Stratus card): what it's for, its highlights and a link to its page. */
defineProps<{ serviceKey: string; name: string; copy: ServiceCopy }>();
const { t } = useT();
</script>

<template>
    <NuxtLink :to="`/features/${serviceKey}`" class="site-card site-card-link group flex flex-col p-6">
        <span :class="['grid size-10 place-items-center rounded-lg', `product-icon-${copy.accent}`]" aria-hidden="true"><Icon :name="copy.icon" class="size-[1.125rem]" /></span>
        <span :class="['mt-5 text-[0.6875rem] font-semibold uppercase tracking-wider', `product-accent-${copy.accent}`]">{{ copy.eyebrow }}</span>
        <span class="mt-1 text-lg font-semibold text-ink">{{ name }}</span>
        <span class="mt-1.5 text-sm leading-relaxed text-muted">{{ copy.card_summary }}</span>
        <ul class="mt-4 space-y-1.5 text-sm text-ink" :aria-label="t(':service highlights', { service: name })">
            <li v-for="feature in copy.card_features" :key="feature" class="flex items-center gap-2"><Icon name="check" :class="['size-3.5 shrink-0', `product-accent-${copy.accent}`]" />{{ feature }}</li>
        </ul>
        <span class="mt-auto flex items-center gap-1 pt-6 text-sm font-medium text-ink group-hover:underline">{{ t('Explore :service', { service: name }) }} <Icon name="arrow-right" class="size-3.5" /></span>
    </NuxtLink>
</template>
