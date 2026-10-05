<script setup lang="ts">
/**
 * A page's sections as tabs (the Acme theme's underlined tabs), linked with `?tab=`: refreshing, bookmarks and redirects
 * after saving open the same tab. The page renders only the current tab's panel. `keep` holds other query parameters
 * the tabs carry over, such as the chosen site.
 */
withDefaults(defineProps<{ tabs: Record<string, string>; current: string; label: string; keep?: Record<string, string | undefined>; counts?: Record<string, number> }>(), { keep: () => ({}), counts: () => ({}) });
</script>

<template>
    <nav class="flex gap-6 overflow-x-auto border-b border-line [scrollbar-width:none]" :aria-label="label">
        <NuxtLink
            v-for="(title, key) in tabs"
            :key="key"
            :to="{ query: { ...keep, tab: key } }"
            :class="['-mb-px flex items-center gap-2 whitespace-nowrap border-b-2 pb-3 text-sm font-medium transition-colors', key === current ? 'border-accent text-ink' : 'border-transparent text-muted hover:text-ink']"
            :aria-current="key === current ? 'page' : undefined"
        >
            {{ title }}<span v-if="counts[key] !== undefined" class="rounded-full bg-black/[.06] px-1.5 text-xs tabular-nums dark:bg-white/10">{{ counts[key] }}</span>
        </NuxtLink>
    </nav>
</template>
