<script setup lang="ts">
import type { ServiceCopy } from '~/types/site';

/** An illustrative glimpse of a service's dashboard, as on the Signal product pages. Every value is sample data. */
const props = withDefaults(defineProps<{ copy: ServiceCopy; headingId?: string }>(), { headingId: 'service-preview-heading' });
const { t } = useT();
const preview = computed(() => props.copy.preview);
const resources = computed(() => [{ label: t('CPU'), value: 18 }, { label: t('Memory'), value: 46 }, { label: t('Disk'), value: 31 }]);
const stages = computed(() => [t('Approve'), t('Release'), t('Verify')]);
</script>

<template>
    <article :class="['ui-panel product-detail-preview min-w-0 rounded-panel p-4 sm:p-5', `product-preview-${copy.accent}`]" :aria-labelledby="headingId">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line pb-4">
            <div class="flex min-w-0 items-center gap-3">
                <span :class="['grid size-10 shrink-0 place-items-center rounded-xl', `product-icon-${copy.accent}`]" aria-hidden="true"><Icon :name="copy.icon" class="size-5" /></span>
                <div class="min-w-0">
                    <h2 :id="headingId" class="truncate text-sm font-extrabold text-ink">{{ preview.title }}</h2>
                    <p class="mt-1 truncate text-xs text-muted">{{ preview.context }}</p>
                </div>
            </div>
            <Badge :tone="preview.status_tone">{{ preview.status }}</Badge>
        </div>
        <p class="mt-4 text-sm leading-6 text-muted">{{ preview.description }}</p>

        <ol v-if="copy.accent === 'deploy'" class="mt-4 grid grid-cols-3 gap-2" :aria-label="t('Illustrative release stages')">
            <li v-for="stage in stages" :key="stage" class="rounded-control border border-line bg-surface-muted p-3">
                <span class="grid size-6 place-items-center rounded-full bg-success text-white" aria-hidden="true"><Icon name="check" class="size-3.5" /></span>
                <p class="mt-2 text-xs font-extrabold text-ink">{{ stage }}</p>
                <p class="mt-1 text-[0.65rem] text-muted">{{ t('Recorded') }}</p>
            </li>
        </ol>
        <div v-else-if="copy.accent === 'infrastructure'" class="mt-4 grid gap-3 rounded-control border border-line bg-surface-muted p-3 sm:p-4">
            <div v-for="resource in resources" :key="resource.label">
                <div class="flex justify-between text-[0.65rem] font-bold"><span class="text-muted">{{ resource.label }}</span><span class="text-ink">{{ resource.value }}%</span></div>
                <ProgressBar :value="resource.value" :label="resource.label" role="meter" class="mt-1.5" />
            </div>
        </div>
        <div v-else-if="copy.accent === 'monitor'" class="mt-4 rounded-control border border-line bg-surface-muted p-3 sm:p-4">
            <div class="flex items-center justify-between gap-3">
                <p class="text-xs font-extrabold text-ink">{{ t('Check history') }}</p>
                <span class="text-[0.65rem] text-muted">{{ t('Last 24 hours') }}</span>
            </div>
            <div class="product-uptime-bars product-uptime-bars-wide mt-3" role="img" :aria-label="t('Illustrative check history using sample data')"><i v-for="hour in 24" :key="hour" /></div>
        </div>
        <div v-else-if="copy.accent === 'analytics'" class="mt-4 rounded-control border border-line bg-surface-muted p-3 sm:p-4">
            <div class="flex items-end justify-between gap-3">
                <div>
                    <p class="text-xs font-extrabold text-ink">{{ t('Visitors') }}</p>
                    <p class="mt-2 text-2xl font-extrabold tracking-tight text-ink">1,248</p>
                </div>
                <span class="text-xs font-bold text-success">↑ 12%</span>
            </div>
            <svg class="product-sparkline mt-3 h-20 w-full" viewBox="0 0 500 112" preserveAspectRatio="none" role="img" :aria-label="t('Illustrative visitor trend using sample data')">
                <path class="product-sparkline-fill" d="M0 91 24 71 49 78 73 61 98 69 123 51 147 57 172 42 196 52 221 34 246 44 270 27 295 38 319 24 344 32 369 15 393 24 418 10 443 18 468 3 500 5V112H0Z" />
                <path class="product-sparkline-line" d="M0 91 24 71 49 78 73 61 98 69 123 51 147 57 172 42 196 52 221 34 246 44 270 27 295 38 319 24 344 32 369 15 393 24 418 10 443 18 468 3 500 5" />
            </svg>
        </div>

        <dl class="mt-4 grid min-w-0 grid-cols-3 gap-2">
            <div v-for="[label, value] in preview.metrics" :key="label" class="ui-card min-w-0 p-3 shadow-none">
                <dt class="truncate text-[0.65rem] font-semibold text-muted">{{ label }}</dt>
                <dd class="mt-1 truncate text-sm font-extrabold text-ink">{{ value }}</dd>
            </div>
        </dl>
        <div class="mt-5 hidden sm:block">
            <h3 class="text-xs font-extrabold text-ink">{{ preview.activity_label }}</h3>
            <ul class="mt-2 divide-y divide-line rounded-control border border-line bg-surface px-3">
                <li v-for="[title, detail, state] in preview.activity" :key="title" class="flex items-center gap-3 py-3">
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-bold text-ink">{{ title }}</p>
                        <p class="mt-1 text-[0.65rem] leading-5 text-muted">{{ detail }}</p>
                    </div>
                    <span class="shrink-0 text-[0.65rem] font-bold text-muted">{{ state }}</span>
                </li>
            </ul>
        </div>
        <p class="mt-3 text-right text-[0.65rem] text-subtle">{{ t('Illustrative interface with sample data.') }}</p>
    </article>
</template>
