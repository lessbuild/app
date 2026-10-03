<script setup lang="ts">
import type { ActivityItem, Dashboard } from '~/types/projects';
import type { Tone } from '~/types/ui';

/**
 * The team's recent deploys, incidents and changes, filtered by kind, refreshed every 15 seconds so a deploy's outcome
 * appears as it lands.
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
    <section class="ui-panel grid gap-4 p-5" aria-labelledby="activity-heading">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="activity-heading" class="text-base font-extrabold text-ink">{{ t('Recent activity') }}</h2>
            <nav class="flex flex-wrap gap-1 text-xs font-bold" :aria-label="t('Filter activity')">
                <NuxtLink
                    v-for="[key, label] in filters"
                    :key="key"
                    :to="{ query: key ? { activity: key } : {} }"
                    :aria-current="(kind ?? '') === key ? 'page' : undefined"
                    :class="['rounded-full px-2.5 py-1', (kind ?? '') === key ? 'bg-primary-soft text-primary' : 'text-muted hover:text-ink']"
                >
                    {{ label }}
                </NuxtLink>
            </nav>
        </div>
        <div aria-live="polite">
            <p v-if="items.length === 0" class="py-3 text-sm text-muted">{{ t('Nothing yet. Deploys, incidents and changes across your projects show up here.') }}</p>
            <ul v-else>
                <li v-for="(item, index) in items" :key="`${item.kind}-${item.at}-${index}`" :class="['flex items-start gap-3 py-3 text-sm', index > 0 && 'border-t border-line']">
                    <span class="mt-0.5 grid size-8 shrink-0 place-items-center rounded-full bg-surface-muted text-muted" aria-hidden="true">
                        <Icon :name="item.icon" class="size-4" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-ink">
                            <NuxtLink v-if="item.url" :to="local(item.url)" class="hover:text-primary hover:underline">{{ item.title }}</NuxtLink>
                            <template v-else>{{ item.title }}</template>
                        </p>
                        <p class="mt-0.5 text-xs text-muted">
                            {{ [item.project, item.actor].filter(Boolean).join(' · ') }}{{ item.project || item.actor ? ' · ' : '' }}
                            <RelativeTime :at="item.at" />
                        </p>
                    </div>
                    <Badge :tone="(item.tone as Tone) ?? 'neutral'">{{ item.outcome }}</Badge>
                </li>
            </ul>
        </div>
    </section>
</template>
