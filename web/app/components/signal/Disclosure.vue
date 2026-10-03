<script setup lang="ts">
/** Signal's disclosure: a titled section that opens and closes. */
defineProps<{ title: string; open?: boolean }>();
</script>

<template>
    <details :open="open" class="group rounded-panel border border-line bg-surface">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-panel px-4 py-3 text-sm font-bold text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
            {{ title }}
            <Icon name="chevron-right" class="h-4 w-4 shrink-0 transition-transform group-open:rotate-90" />
        </summary>
        <div class="border-t border-line p-4"><slot /></div>
    </details>
</template>
