<script setup lang="ts">
/** Signal's table: a scrollable, labelled region with a hidden caption; the `head` slot is the header row. */
withDefaults(defineProps<{ caption: string; framed?: boolean; tableClass?: string }>(), { framed: true, tableClass: undefined });
</script>

<template>
    <div role="region" :aria-label="caption" tabindex="0" :class="['ui-table-wrap', !framed && 'rounded-none border-0']">
        <table :class="['ui-table', tableClass]">
            <caption class="sr-only">{{ caption }}</caption>
            <thead v-if="$slots.head"><slot name="head" /></thead>
            <tbody><slot /></tbody>
        </table>
    </div>
</template>
