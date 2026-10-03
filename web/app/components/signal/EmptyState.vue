<script setup lang="ts">
/** Signal's empty state: what goes here, and (in the `action` slot) how to add the first one. */
withDefaults(defineProps<{ title: string; description?: string; icon?: string }>(), { description: undefined, icon: 'information-circle' });
</script>

<template>
    <div class="ui-card p-8 text-center" data-ui-feedback="empty">
        <div class="mx-auto max-w-2xl">
            <span class="mx-auto grid h-11 w-11 place-items-center rounded-card bg-primary-soft text-[var(--ui-primary)]">
                <Icon :name="icon" class="h-5 w-5" />
            </span>
            <h2 class="mt-4 text-base font-extrabold text-ink">{{ title }}</h2>
            <p v-if="description" class="mt-2 text-sm leading-6 text-muted">{{ description }}</p>
            <div v-if="$slots.action" class="mt-6 flex flex-wrap justify-center gap-2"><slot name="action" /></div>
        </div>
    </div>
</template>
