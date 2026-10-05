<script setup lang="ts">
import type { SiteScore } from '~/types/audit';

/** One tile per site with its overall score; the audited site first and emphasised, each with a word for the score. */
const props = defineProps<{ sites: SiteScore[] }>();
const { t } = useT();
const words = computed(() => ({ success: t('Good'), warning: t('Fair'), danger: t('Poor') }));
const best = computed(() => Math.max(...props.sites.slice(1).map((site) => site.score)));
</script>

<template>
    <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="(site, index) in sites" :key="site.key" :class="['ui-stat', index === 0 && 'border-[var(--audit-chart-site)] ring-1 ring-[var(--audit-chart-site)]']">
            <dt class="flex items-center justify-between gap-2 text-xs font-bold text-muted">
                <span class="truncate">{{ index === 0 ? t(':name (your site)', { name: site.name }) : site.name }}</span>
                <AcmeBadge :tone="acmeTone(scoreTone(site.score))">{{ words[scoreTone(site.score)] }}</AcmeBadge>
            </dt>
            <dd class="mt-3 text-4xl font-semibold tracking-tight text-ink">{{ site.score }}</dd>
            <dd v-if="index === 0 && sites.length > 1" class="mt-1 text-xs text-muted">
                {{ site.score >= best ? t('Ahead of every competitor') : t(':points points behind the best competitor', { points: best - site.score }) }}
            </dd>
        </div>
    </dl>
</template>
