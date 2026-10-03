<script setup lang="ts">
/** Signal's text link. */
withDefaults(defineProps<{ to: string | { query: Record<string, string | null | undefined> }; variant?: 'primary' | 'muted' | 'quiet'; size?: 'sm' | 'md' | 'inline' | 'none'; layout?: 'inline' | 'stack' | 'block' }>(), { variant: 'primary', size: 'md', layout: 'inline' });
const variants = { primary: 'font-extrabold text-primary hover:bg-primary-soft', muted: 'font-bold text-muted hover:bg-surface-muted hover:text-ink', quiet: 'font-semibold text-subtle hover:text-ink' };
const sizes = { sm: 'min-h-9 px-3 text-sm', md: 'min-h-10 px-3 text-sm', inline: 'min-h-0 px-0 text-sm', none: '' };
const layouts = { inline: 'inline-flex items-center gap-2', stack: 'flex flex-col items-center', block: 'flex items-center' };
</script>

<template>
    <NuxtLink :to="to" :class="['ui-link rounded-control transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus', layouts[layout], variants[variant], sizes[size]]"><slot /></NuxtLink>
</template>
