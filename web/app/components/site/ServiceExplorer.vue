<script setup lang="ts">
import type { ServiceCopy } from '~/types/site';

/** The product explorer: one tab per service (arrow keys move between them), each showing its part and a glimpse of it. */
const props = defineProps<{ services: Array<{ key: string; name: string; copy: ServiceCopy }> }>();
const { t } = useT();
const active = ref(props.services[0]?.key ?? '');
const tabs = ref<HTMLButtonElement[]>([]);
// One column per service (written out so Tailwind keeps the classes).
const columns = computed(() => ({ 3: 'grid-cols-3', 4: 'grid-cols-4', 5: 'grid-cols-5', 6: 'grid-cols-6' } as Record<number, string>)[props.services.length] ?? 'grid-cols-4');

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
            <div>
                <p class="ui-eyebrow">{{ t('Product explorer') }}</p>
                <h3 id="service-explorer-heading" class="mt-2 text-2xl font-extrabold tracking-tight text-ink sm:text-3xl">{{ t('A clear view for every kind of work.') }}</h3>
            </div>
            <p class="hidden max-w-md text-sm leading-6 text-muted sm:block">{{ t('See each service’s part in a project. Open its page for everything it does.') }}</p>
        </div>
        <div :class="['product-explorer-tabs mt-6 grid rounded-control border border-line bg-surface p-1', columns]" role="tablist" :aria-label="t('Explore the services')">
            <button
                v-for="(service, index) in services"
                :id="`service-tab-${service.key}`"
                :key="service.key"
                ref="tabs"
                type="button"
                role="tab"
                class="product-explorer-tab"
                :aria-controls="`service-panel-${service.key}`"
                :aria-selected="active === service.key"
                :tabindex="active === service.key ? 0 : -1"
                @click="active = service.key"
                @keydown="keydown($event, index)"
            >
                <Icon :name="service.copy.icon" class="size-4" /><span>{{ service.name }}</span>
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
            class="product-explorer-panel"
        >
            <div class="grid min-w-0 gap-5 rounded-panel border border-line bg-surface p-4 shadow-soft sm:p-6 md:grid-cols-[.8fr_1.2fr] md:items-center">
                <div>
                    <p :class="['text-xs font-extrabold uppercase tracking-[0.14em]', `product-accent-${service.copy.accent}`]">{{ service.copy.eyebrow }}</p>
                    <h4 class="mt-2 text-xl font-extrabold tracking-tight text-ink">{{ service.copy.suite.title }}</h4>
                    <p class="mt-2 text-sm leading-6 text-muted">{{ service.copy.suite.description }}</p>
                    <ul class="mt-4 hidden gap-2 sm:grid">
                        <li v-for="feature in service.copy.suite.features" :key="feature" class="flex items-start gap-2 text-sm font-semibold text-ink"><Icon name="check" class="mt-0.5 size-4 shrink-0 text-success" /><span>{{ feature }}</span></li>
                    </ul>
                    <NuxtLink :to="`/features/${service.key}`" class="ui-btn ui-btn-secondary mt-5">{{ t('Explore :service', { service: service.name }) }} <Icon name="arrow-right" class="size-4" /></NuxtLink>
                </div>
                <ServicePreview :copy="service.copy" :heading-id="`service-preview-${service.key}`" class="product-explorer-preview" />
            </div>
        </div>
    </section>
</template>
