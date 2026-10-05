<script setup lang="ts">
import type { Finding } from '~/types/audit';

/** One finding in full: the evidence with the problem outlined, what to change, and the mock-up of the fix. */
defineProps<{ finding: Finding; screen: { width: number; height: number } }>();
const { t } = useT();
const labels = useFindingLabels();
</script>

<template>
    <article class="grid gap-6">
        <div class="flex flex-wrap items-center gap-2">
            <AcmeBadge :tone="acmeTone(labels.severityTone[finding.severity])">{{ labels.severity[finding.severity] }}</AcmeBadge>
            <AcmeBadge>{{ finding.categoryLabel }}</AcmeBadge>
            <AcmeBadge>{{ labels.effort[finding.effort] }}</AcmeBadge>
            <a v-if="finding.pageUrl" :href="finding.pageUrl" class="ui-link truncate text-xs" target="_blank" rel="noreferrer noopener">{{ finding.pageUrl }}</a>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <section class="grid content-start gap-2">
                <h3 class="text-sm font-semibold text-ink">{{ t('What’s wrong') }}</h3>
                <p class="text-sm leading-6 text-muted">{{ finding.detail }}</p>
            </section>
            <section class="grid content-start gap-2">
                <h3 class="text-sm font-semibold text-ink">{{ t('What to change') }}</h3>
                <p class="text-sm leading-6 text-muted">{{ finding.recommendation }}</p>
                <p v-if="finding.competitorNote" class="rounded-control bg-surface-muted p-3 text-sm leading-6 text-muted"><strong class="text-ink">{{ t('Competitors:') }}</strong> {{ finding.competitorNote }}</p>
            </section>
        </div>
        <div v-if="finding.screenshotUrl || finding.mockupUrl" :class="['grid gap-4', finding.screenshotUrl && finding.mockupUrl && 'lg:grid-cols-2']">
            <section v-if="finding.screenshotUrl" class="grid content-start gap-2">
                <h3 class="text-xs font-semibold uppercase tracking-[0.14em] text-subtle">{{ t('Now') }}</h3>
                <AuditScreenshot :src="finding.screenshotUrl" :alt="t('The page as the visitor saw it, with the problem outlined')" :boxes="finding.boxes" :screen="screen" />
            </section>
            <section v-if="finding.mockupUrl" class="grid content-start gap-2">
                <h3 class="text-xs font-semibold uppercase tracking-[0.14em] text-subtle">{{ t('Suggested') }}</h3>
                <figure class="overflow-hidden rounded-card border border-line bg-surface">
                    <img :src="finding.mockupUrl" :alt="t('A mock-up of the section with the change made')" loading="lazy" class="block h-auto w-full">
                    <figcaption class="border-t border-line px-3 py-2 text-xs text-muted">{{ t('A mock-up to show the idea, not a finished design.') }}</figcaption>
                </figure>
            </section>
        </div>
    </article>
</template>
