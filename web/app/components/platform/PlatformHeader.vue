<script setup lang="ts">
import type { MenuItem } from '~/components/acme/Menu.vue';

/**
 * The top of an app page (the Acme theme's Platform page header): the title and a line about it, a project switcher
 * on project pages, the page's actions (the `actions` slot), and the area's sections as a row of tabs.
 */
defineProps<{ title: string; subtitle?: string | null }>();
const { t } = useT();
const route = useRoute();
const shell = useShell();
const sections = computed(() => shell.value?.sectionNav ?? []);
const current = computed(() => currentNavUrl(sections.value, route));
const project = computed(() => shell.value?.project ?? null);
const service = computed(() => (typeof route.meta.service === 'string' ? route.meta.service : null));
const style = computed(() => serviceStyle(service.value));
// Switching project keeps you in the same section where you can: its landing page exists in every project.
const switcher = computed<MenuItem[]>(() => {
    if (!project.value || !shell.value) {
        return [];
    }
    const here = current.value ?? `/projects/${project.value.id}`;
    return [
        ...shell.value.projects.map((item) => ({ label: item.name, checked: item.id === project.value?.id, to: here.replace(`/projects/${project.value!.id}`, `/projects/${item.id}`) })),
        { divider: true },
        { label: t('All projects'), icon: 'grid', to: '/dashboard' },
    ];
});
</script>

<template>
    <div>
        <header class="flex flex-wrap items-end justify-between gap-4 pb-6">
            <div class="min-w-0">
                <h1 class="text-2xl font-semibold tracking-tight text-ink sm:text-[1.75rem]">{{ title }}</h1>
                <p v-if="subtitle" class="mt-1 text-muted">{{ subtitle }}</p>
            </div>
            <div v-if="switcher.length > 0 || $slots.actions" class="flex flex-wrap gap-2">
                <AcmeMenu v-if="switcher.length > 0" :items="switcher" :label="t('Switch project')" align="right">
                    <template #trigger="{ attrs }">
                        <button v-bind="attrs" type="button" class="flex h-9 items-center gap-2 rounded-xl border border-line bg-surface px-3 text-sm text-ink shadow-card hover:bg-black/[.02] dark:hover:bg-white/[.04]">
                            <span :class="['grid size-5 place-items-center rounded-md text-[0.625rem] font-bold text-white', style.tone]">{{ project!.name.slice(0, 1).toUpperCase() }}</span>{{ project!.name }}<AcmeIcon name="updown" :size="14" class="text-muted" />
                        </button>
                    </template>
                </AcmeMenu>
                <slot name="actions" />
            </div>
        </header>
        <nav v-if="sections.length > 0" class="mb-6 overflow-x-auto border-b border-line [scrollbar-width:none]" :aria-label="shell?.sectionLabel ?? t('Sections')">
            <ul class="flex min-w-max gap-1">
                <li v-for="item in sections" :key="item.url">
                    <NuxtLink :to="item.url" :class="['relative block px-3 pb-3 pt-1 text-sm transition-colors', item.url === current ? 'font-medium text-ink' : 'text-muted hover:text-ink']" :aria-current="item.url === current ? 'page' : undefined">
                        {{ item.label }}<span v-if="item.url === current" class="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-accent" aria-hidden="true" />
                    </NuxtLink>
                </li>
            </ul>
        </nav>
    </div>
</template>
