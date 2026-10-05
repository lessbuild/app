<script setup lang="ts">
import type { ActivityItem, Dashboard } from '~/types/projects';

/**
 * The team's recent deploys, incidents and changes (the Acme theme's activity card), filtered by kind, refreshed every
 * 15 seconds so a deploy's outcome appears as it lands.
 */
const props = defineProps<{ initial: ActivityItem[]; kind: string | null; kinds: Record<string, string> }>();
const { t } = useT();
const items = ref(props.initial);
let timer: number | undefined;

watch(() => props.initial, (value) => (items.value = value));
onMounted(() => {
    timer = window.setInterval(async () => {
        const data = await send<Dashboard>('GET', `/dashboard${props.kind ? `?activity=${props.kind}` : ''}`).catch(() => null);
        if (data) {
            items.value = data.activity;
        }
    }, 15000);
});
onBeforeUnmount(() => window.clearInterval(timer));

const filters = computed(() => [['', t('All')], ...Object.entries(props.kinds)] as Array<[string, string]>);
</script>

<template>
    <AcmeCard :title="t('Recent activity')" :description="t('Deploys, incidents and changes across your projects.')">
        <template #action>
            <nav class="flex flex-wrap gap-2" :aria-label="t('Filter activity')">
                <NuxtLink
                    v-for="[key, label] in filters"
                    :key="key"
                    :to="{ query: key ? { activity: key } : {} }"
                    :aria-current="(kind ?? '') === key ? 'page' : undefined"
                    :class="['rounded-full border px-3 py-1.5 text-sm transition-colors', (kind ?? '') === key ? 'border-accent bg-accent text-accent-fg' : 'border-line bg-surface text-muted hover:text-ink']"
                >{{ label }}</NuxtLink>
            </nav>
        </template>
        <div aria-live="polite">
            <p v-if="items.length === 0" class="text-sm text-muted">{{ t('Nothing yet. Deploys, incidents and changes across your projects show up here.') }}</p>
            <ul v-else class="divide-y divide-line">
                <li v-for="(item, index) in items" :key="`${item.kind}-${item.at}-${index}`" class="flex items-start gap-3 py-3 text-sm first:pt-0 last:pb-0">
                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-black/[.05] text-muted dark:bg-white/10" aria-hidden="true"><Icon :name="item.icon" class="size-4" /></span>
                    <span class="min-w-0 flex-1">
                        <NuxtLink v-if="item.url" :to="local(item.url)" class="block font-medium text-ink hover:underline">{{ item.title }}</NuxtLink>
                        <span v-else class="block font-medium text-ink">{{ item.title }}</span>
                        <span class="text-xs text-muted">{{ [item.project, item.actor].filter(Boolean).join(' · ') }}{{ item.project || item.actor ? ' · ' : '' }}<RelativeTime :at="item.at" /></span>
                    </span>
                    <AcmeBadge :tone="acmeTone(item.tone)">{{ item.outcome }}</AcmeBadge>
                </li>
            </ul>
        </div>
    </AcmeCard>
</template>
