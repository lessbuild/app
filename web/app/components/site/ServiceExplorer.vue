<script setup lang="ts">
import type { ServiceCopy } from '~/types/site';

/** The product explorer: one tab per service (arrow keys move between them), each showing its part and a glimpse of it. */
const props = defineProps<{ services: Array<{ key: string; name: string; copy: ServiceCopy }> }>();
const { t } = useT();
const active = ref(props.services[0]?.key ?? '');
const tabs = ref<HTMLButtonElement[]>([]);
// One column per service (written out so Tailwind keeps the classes).
const columns = computed(() => ({ 3: 'sm:grid-cols-3', 4: 'sm:grid-cols-4', 5: 'sm:grid-cols-5', 6: 'sm:grid-cols-6' } as Record<number, string>)[props.services.length] ?? 'sm:grid-cols-4');

/** Move between tabs with the arrow keys, Home and End, as a tab list does. */
function keydown(event: KeyboardEvent, index: number) {
    const last = props.services.length - 1;
    const next = { ArrowRight: index === last ? 0 : index + 1, ArrowLeft: index === 0 ? last : index - 1, Home: 0, End: last }[event.key];
    if (next === undefined) {
        return;
    }
    event.preventDefault();
    active.value = props.services[next]?.key ?? active.value;
    tabs.value[next]?.focus();
}
</script>

<template>
    <section id="service-explorer" class="scroll-mt-20" aria-labelledby="service-explorer-heading">
        <div class="flex flex-col justify-between gap-4 md:flex-row md:items-end">
            <h2 id="service-explorer-heading" class="site-h2">{{ t('A clear view for every kind of work.') }}</h2>
            <p class="hidden max-w-md text-sm leading-6 text-muted sm:block">{{ t('See each service’s part in a project. Open its page for everything it does.') }}</p>
        </div>
        <div :class="['mt-10 grid overflow-hidden rounded-xl border border-line max-sm:grid-cols-2', columns]" role="tablist" :aria-label="t('Explore the services')">
            <button
                v-for="(service, index) in services"
                :id="`service-tab-${service.key}`"
                :key="service.key"
                ref="tabs"
                type="button"
                role="tab"
                :class="['relative flex items-center justify-center gap-2 border-line py-4 text-sm font-medium transition', active === service.key ? 'bg-surface-muted text-ink' : 'text-muted hover:text-ink', index > 0 && 'sm:border-l', 'max-sm:border-b']"
                :aria-controls="`service-panel-${service.key}`"
                :aria-selected="active === service.key"
                :tabindex="active === service.key ? 0 : -1"
                @click="active = service.key"
                @keydown="keydown($event, index)"
            >
                <Icon :name="service.copy.icon" class="size-4" /><span>{{ service.name }}</span>
                <span v-if="active === service.key" class="absolute inset-x-6 bottom-0 h-0.5 bg-primary" aria-hidden="true" />
            </button>
        </div>
        <div
            v-for="service in services"
            v-show="active === service.key"
            :id="`service-panel-${service.key}`"
            :key="service.key"
            role="tabpanel"
            tabindex="0"
            :aria-labelledby="`service-tab-${service.key}`"
            class="grid min-w-0 items-center gap-10 pt-12 lg:grid-cols-[1fr_1.15fr]"
        >
            <div>
                <p :class="['text-xs font-semibold uppercase tracking-wider', `product-accent-${service.copy.accent}`]">{{ service.copy.eyebrow }}</p>
                <h3 class="mt-2 text-2xl font-semibold tracking-tight text-ink">{{ service.copy.suite.title }}</h3>
                <p class="mt-3 text-muted">{{ service.copy.suite.description }}</p>
                <ul class="mt-5 space-y-2 text-sm text-ink">
                    <li v-for="feature in service.copy.suite.features" :key="feature" class="flex items-center gap-2"><Icon name="check" :class="['size-3.5 shrink-0', `product-accent-${service.copy.accent}`]" />{{ feature }}</li>
                </ul>
                <NuxtLink :to="`/features/${service.key}`" class="site-btn-2 mt-6">{{ t('Explore :service', { service: service.name }) }} <Icon name="arrow-right" class="size-3.5" /></NuxtLink>
            </div>
            <ServicePreview :copy="service.copy" :heading-id="`service-preview-${service.key}`" />
        </div>
    </section>
</template>
